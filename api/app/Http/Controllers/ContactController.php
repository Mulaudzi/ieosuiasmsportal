<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\OptOut;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;
use League\Csv\Writer;

/**
 * Contact Controller
 * 
 * Handles contact and contact group management, including import/export.
 */
class ContactController extends Controller
{
    /**
     * List all contacts
     * 
     * GET /api/contacts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $perPage = (int) $request->query('per_page', 20);
        $groupId = $request->query('group_id');
        $search = $request->query('search');

        $query = Contact::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'desc');

        if ($groupId) {
            $query->whereHas('groups', function ($q) use ($groupId) {
                $q->where('contact_groups.id', $groupId);
            });
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        $contacts = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $contacts->items(),
            'meta' => [
                'current_page' => $contacts->currentPage(),
                'last_page' => $contacts->lastPage(),
                'per_page' => $contacts->perPage(),
                'total' => $contacts->total(),
            ],
        ]);
    }

    /**
     * Create a new contact
     * 
     * POST /api/contacts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'phone' => ['required_without:email', 'nullable', 'string', 'max:20'],
            'email' => ['required_without:phone', 'nullable', 'email', 'max:255'],
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name' => ['sometimes', 'string', 'max:100'],
            'group_ids' => ['sometimes', 'array'],
            'group_ids.*' => ['integer', 'exists:contact_groups,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        // Check for duplicates
        $existingQuery = Contact::where('user_id', $user->id);
        if ($request->has('phone') && $request->input('phone')) {
            $existingQuery->orWhere('phone', $request->input('phone'));
        }
        if ($request->has('email') && $request->input('email')) {
            $existingQuery->orWhere('email', $request->input('email'));
        }

        if ($existingQuery->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'Contact with this phone or email already exists',
            ], 409);
        }

        try {
            DB::beginTransaction();

            $contact = Contact::create([
                'user_id' => $user->id,
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'first_name' => $request->input('first_name'),
                'last_name' => $request->input('last_name'),
                'source' => 'manual',
            ]);

            // Add to groups if specified
            if ($request->has('group_ids')) {
                $contact->groups()->attach($request->input('group_ids'));
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => $contact,
                'message' => 'Contact created successfully',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to create contact',
            ], 500);
        }
    }

    /**
     * Import contacts from CSV/Excel
     * 
     * POST /api/contacts/import
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:10240'],
            'group_id' => ['sometimes', 'integer', 'exists:contact_groups,id'],
            'has_header' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $file = $request->file('file');
        $hasHeader = $request->boolean('has_header', true);
        $groupId = $request->input('group_id');

        try {
            // Parse CSV
            $csv = Reader::createFromPath($file->getPathname(), 'r');
            if ($hasHeader) {
                $csv->setHeaderOffset(0);
            }

            $imported = 0;
            $duplicates = 0;
            $errors = 0;

            DB::beginTransaction();

            foreach ($csv->getRecords() as $record) {
                // Map columns (adjust based on expected format)
                $phone = $record['phone'] ?? $record['Phone'] ?? $record['mobile'] ?? $record['Mobile'] ?? null;
                $email = $record['email'] ?? $record['Email'] ?? null;
                $firstName = $record['first_name'] ?? $record['FirstName'] ?? $record['name'] ?? $record['Name'] ?? null;
                $lastName = $record['last_name'] ?? $record['LastName'] ?? null;

                if (!$phone && !$email) {
                    $errors++;
                    continue;
                }

                // Check for duplicates
                $exists = Contact::where('user_id', $user->id)
                    ->where(function ($q) use ($phone, $email) {
                        if ($phone) $q->orWhere('phone', $phone);
                        if ($email) $q->orWhere('email', $email);
                    })
                    ->exists();

                if ($exists) {
                    $duplicates++;
                    continue;
                }

                // Check opt-out status
                $isOptedOut = OptOut::where('user_id', $user->id)
                    ->where(function ($q) use ($phone, $email) {
                        if ($phone) $q->orWhere('recipient', $phone);
                        if ($email) $q->orWhere('recipient', $email);
                    })
                    ->exists();

                $contact = Contact::create([
                    'user_id' => $user->id,
                    'phone' => $phone,
                    'email' => $email,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'opt_out' => $isOptedOut,
                    'source' => 'import',
                ]);

                if ($groupId) {
                    $contact->groups()->attach($groupId);
                }

                $imported++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'imported' => $imported,
                    'duplicatesSkipped' => $duplicates,
                    'errors' => $errors,
                ],
                'message' => "Successfully imported {$imported} contacts",
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'error' => 'Failed to import contacts: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Export contacts to CSV
     * 
     * GET /api/contacts/export
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $groupId = $request->query('group_id');

        $query = Contact::where('user_id', $user->id)
            ->whereNull('deleted_at');

        if ($groupId) {
            $query->whereHas('groups', function ($q) use ($groupId) {
                $q->where('contact_groups.id', $groupId);
            });
        }

        $contacts = $query->get();

        $csv = Writer::createFromString();
        $csv->insertOne(['Phone', 'Email', 'First Name', 'Last Name', 'Opt Out', 'Created At']);

        foreach ($contacts as $contact) {
            $csv->insertOne([
                $contact->phone,
                $contact->email,
                $contact->first_name,
                $contact->last_name,
                $contact->opt_out ? 'Yes' : 'No',
                $contact->created_at->format('Y-m-d H:i:s'),
            ]);
        }

        return response($csv->toString(), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="contacts-export.csv"',
        ]);
    }

    /**
     * Delete contacts
     * 
     * DELETE /api/contacts
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        $ids = $request->input('ids');

        $deleted = Contact::where('user_id', $user->id)
            ->whereIn('id', $ids)
            ->delete();

        return response()->json([
            'success' => true,
            'data' => ['deleted' => $deleted],
            'message' => "Deleted {$deleted} contacts",
        ]);
    }

    /**
     * List contact groups
     * 
     * GET /api/contacts/groups
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function groups(Request $request): JsonResponse
    {
        $user = $request->user();

        $groups = ContactGroup::where('user_id', $user->id)
            ->whereNull('deleted_at')
            ->withCount('contacts')
            ->orderBy('name')
            ->get()
            ->map(fn ($group) => [
                'id' => $group->id,
                'name' => $group->name,
                'description' => $group->description,
                'contactCount' => $group->contacts_count,
                'createdAt' => $group->created_at->toISOString(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $groups,
        ]);
    }

    /**
     * Create a contact group
     * 
     * POST /api/contacts/groups
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function createGroup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:500'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $group = ContactGroup::create([
            'user_id' => $user->id,
            'name' => $request->input('name'),
            'description' => $request->input('description'),
        ]);

        return response()->json([
            'success' => true,
            'data' => $group,
            'message' => 'Group created successfully',
        ], 201);
    }

    /**
     * Add contacts to a group
     * 
     * POST /api/contacts/groups/{id}/add
     * 
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function addToGroup(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contact_ids' => ['required', 'array'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $group = ContactGroup::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$group) {
            return response()->json([
                'success' => false,
                'error' => 'Group not found',
            ], 404);
        }

        // Verify contacts belong to user
        $contactIds = Contact::where('user_id', $user->id)
            ->whereIn('id', $request->input('contact_ids'))
            ->pluck('id');

        $group->contacts()->syncWithoutDetaching($contactIds);

        return response()->json([
            'success' => true,
            'message' => 'Contacts added to group',
        ]);
    }
}
