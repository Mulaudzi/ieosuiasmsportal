<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserRole;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\ContactGroup;
use App\Models\Template;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@ieosuia.com',
            'password' => Hash::make('admin123'),
            'account_type' => 'enterprise',
            'email_verified_at' => now(),
        ]);
        UserRole::assignRole($admin->id, 'admin');
        $admin->wallet->update(['balance' => 10000.00]);

        // Create test user
        $testUser = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password123'),
            'account_type' => 'business',
            'email_verified_at' => now(),
        ]);
        $testUser->wallet->update(['balance' => 500.00]);

        // Create demo user
        $demoUser = User::create([
            'name' => 'Demo User',
            'email' => 'demo@ieosuia.com',
            'password' => Hash::make('demo123'),
            'account_type' => 'individual',
            'email_verified_at' => now(),
        ]);
        $demoUser->wallet->update(['balance' => 100.00]);

        // Create contact groups for test user
        $group1 = ContactGroup::create([
            'user_id' => $testUser->id,
            'name' => 'VIP Customers',
            'description' => 'High-value customers',
            'color' => '#22c55e',
        ]);

        $group2 = ContactGroup::create([
            'user_id' => $testUser->id,
            'name' => 'Newsletter Subscribers',
            'description' => 'Email newsletter list',
            'color' => '#3b82f6',
        ]);

        // Create contacts
        $contacts = [
            ['first_name' => 'John', 'last_name' => 'Doe', 'phone' => '27821234567', 'email' => 'john@example.com'],
            ['first_name' => 'Jane', 'last_name' => 'Smith', 'phone' => '27829876543', 'email' => 'jane@example.com'],
            ['first_name' => 'Mike', 'last_name' => 'Johnson', 'phone' => '27831112222', 'email' => 'mike@example.com'],
            ['first_name' => 'Sarah', 'last_name' => 'Williams', 'phone' => '27843334444', 'email' => 'sarah@example.com'],
            ['first_name' => 'David', 'last_name' => 'Brown', 'phone' => '27855556666', 'email' => 'david@example.com'],
        ];

        foreach ($contacts as $contactData) {
            $contact = Contact::create(array_merge($contactData, [
                'user_id' => $testUser->id,
                'source' => 'seed',
            ]));
            $contact->groups()->attach($group1->id);
        }

        // Create templates
        Template::create([
            'user_id' => $testUser->id,
            'name' => 'Welcome SMS',
            'channel' => 'sms',
            'content' => 'Hi {name}, welcome to IEOSUIA! Your account is now active. Reply STOP to opt out.',
            'variables' => ['name'],
            'category' => 'Onboarding',
        ]);

        Template::create([
            'user_id' => $testUser->id,
            'name' => 'Promo Alert',
            'channel' => 'sms',
            'content' => 'SPECIAL OFFER: {discount}% off all products! Use code {code}. Valid until {expiry}. Shop now!',
            'variables' => ['discount', 'code', 'expiry'],
            'category' => 'Marketing',
        ]);

        Template::create([
            'user_id' => $testUser->id,
            'name' => 'Appointment Reminder',
            'channel' => 'sms',
            'content' => 'Reminder: You have an appointment on {date} at {time}. Reply YES to confirm or call us to reschedule.',
            'variables' => ['date', 'time'],
            'category' => 'Reminders',
        ]);

        Template::create([
            'user_id' => $testUser->id,
            'name' => 'Welcome Email',
            'channel' => 'email',
            'subject' => 'Welcome to IEOSUIA, {name}!',
            'content' => '<h1>Welcome, {name}!</h1><p>Thank you for joining IEOSUIA. Your account is ready to use.</p><p>Get started by creating your first campaign.</p>',
            'variables' => ['name'],
            'category' => 'Onboarding',
        ]);

        // Create sample campaigns
        Campaign::create([
            'user_id' => $testUser->id,
            'name' => 'Black Friday Promo',
            'channel' => 'sms',
            'status' => 'Completed',
            'message' => 'BLACK FRIDAY SALE! 50% off everything. Use code BF50. Shop now at our store!',
            'sender_id' => 'IEOSUIA',
            'total_recipients' => 150,
            'sent_count' => 150,
            'delivered_count' => 142,
            'failed_count' => 8,
            'estimated_cost' => 57.00,
            'actual_cost' => 53.96,
            'sent_at' => now()->subDays(5),
            'completed_at' => now()->subDays(5),
        ]);

        Campaign::create([
            'user_id' => $testUser->id,
            'name' => 'January Newsletter',
            'channel' => 'email',
            'status' => 'Completed',
            'subject' => 'January Updates from IEOSUIA',
            'message' => '<h1>January Newsletter</h1><p>Check out our latest updates...</p>',
            'from_email' => 'newsletter@ieosuia.com',
            'from_name' => 'IEOSUIA Newsletter',
            'total_recipients' => 500,
            'sent_count' => 500,
            'delivered_count' => 485,
            'failed_count' => 15,
            'estimated_cost' => 25.00,
            'actual_cost' => 24.25,
            'sent_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        Campaign::create([
            'user_id' => $testUser->id,
            'name' => 'Weekend Special',
            'channel' => 'sms',
            'status' => 'Draft',
            'message' => 'This weekend only: Free delivery on all orders over R500!',
            'sender_id' => 'IEOSUIA',
            'total_recipients' => 0,
            'estimated_cost' => 0,
        ]);

        $this->command->info('Database seeded successfully!');
        $this->command->info('Admin: admin@ieosuia.com / admin123');
        $this->command->info('Test: test@example.com / password123');
        $this->command->info('Demo: demo@ieosuia.com / demo123');
    }
}
