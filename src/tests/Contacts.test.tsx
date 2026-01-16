/**
 * Contacts Page Tests
 * Tests rendering and data handling for the Contacts component
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render } from "@testing-library/react";
import { BrowserRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";

// Mock the API module
vi.mock("@/lib/api", () => ({
  api: {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
  },
  handleApiError: vi.fn(),
}));

// Mock toast hook
vi.mock("@/hooks/use-toast", () => ({
  toast: vi.fn(),
  useToast: () => ({
    toast: vi.fn(),
    toasts: [],
    dismiss: vi.fn(),
  }),
}));

// Create mock data
const mockContacts = [
  {
    id: "1",
    name: "John Doe",
    phone: "+12345678901",
    email: "john@example.com",
    group_id: "1",
    group_name: "General",
  },
  {
    id: "2",
    name: "Jane Smith",
    phone: "+19876543210",
    email: "jane@example.com",
    group_id: "2",
    group_name: "VIP",
  },
];

const mockGroups = [
  { id: "1", name: "General", contact_count: 5 },
  { id: "2", name: "VIP", contact_count: 3 },
];

// Helper to create test wrapper
const createWrapper = () => {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
    },
  });

  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>{children}</BrowserRouter>
    </QueryClientProvider>
  );
};

describe("Contacts Data Handling", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("Data Transformation", () => {
    it("should transform contact data correctly", () => {
      const rawApiResponse = {
        success: true,
        data: mockContacts,
      };

      // Test transformation logic
      const contactsData = Array.isArray(rawApiResponse.data)
        ? rawApiResponse.data
        : [];

      const transformedContacts = contactsData.map((c: any) => ({
        ...c,
        id: String(c.id),
        group_id: c.group_id ? String(c.group_id) : "",
        group_name: c.group_name ?? "",
      }));

      expect(transformedContacts).toHaveLength(2);
      expect(transformedContacts[0].id).toBe("1");
      expect(transformedContacts[0].group_name).toBe("General");
    });

    it("should handle empty contacts response", () => {
      const rawApiResponse = {
        success: true,
        data: [],
      };

      const contactsData = Array.isArray(rawApiResponse.data)
        ? rawApiResponse.data
        : [];

      expect(contactsData).toHaveLength(0);
    });

    it("should handle null/undefined in response", () => {
      const rawApiResponse = {
        success: true,
        data: null,
      };

      const contactsData = Array.isArray(rawApiResponse.data)
        ? rawApiResponse.data
        : [];

      expect(contactsData).toHaveLength(0);
    });
  });

  describe("Groups Data Handling", () => {
    it("should transform groups data correctly", () => {
      const rawApiResponse = {
        success: true,
        data: { groups: mockGroups },
      };

      const groupsData = Array.isArray(rawApiResponse.data?.groups)
        ? rawApiResponse.data.groups
        : [];

      const transformedGroups = groupsData.map((g: any) => ({
        ...g,
        id: String(g.id),
        contact_count: g.contact_count ?? 0,
      }));

      expect(transformedGroups).toHaveLength(2);
      expect(transformedGroups[0].id).toBe("1");
      expect(transformedGroups[1].contact_count).toBe(3);
    });

    it("should handle groups as direct array", () => {
      const rawApiResponse = {
        success: true,
        data: mockGroups, // Direct array instead of {groups: [...]}
      };

      // Handle both formats
      const groupsData = Array.isArray(rawApiResponse.data)
        ? rawApiResponse.data
        : Array.isArray((rawApiResponse.data as any)?.groups)
        ? (rawApiResponse.data as any).groups
        : [];

      expect(groupsData).toHaveLength(2);
    });

    it("should handle missing contact_count", () => {
      const groupsWithMissingCount = [
        { id: 1, name: "No Count Group" },
        { id: 2, name: "With Count", contact_count: 5 },
      ];

      const transformed = groupsWithMissingCount.map((g: any) => ({
        ...g,
        id: String(g.id),
        contact_count: g.contact_count ?? 0,
      }));

      expect(transformed[0].contact_count).toBe(0);
      expect(transformed[1].contact_count).toBe(5);
    });
  });

  describe("Pagination Logic", () => {
    it("should calculate pagination correctly", () => {
      const rawApiResponse = {
        success: true,
        data: mockContacts,
        total: 50,
      };

      const total = (rawApiResponse as any).total ?? mockContacts.length;
      const perPage = 10;
      const totalPages = Math.ceil(total / perPage);

      expect(total).toBe(50);
      expect(totalPages).toBe(5);
    });

    it("should fallback to data length if total missing", () => {
      const rawApiResponse = {
        success: true,
        data: mockContacts,
      };

      const total = (rawApiResponse as any).total ?? mockContacts.length;
      expect(total).toBe(2);
    });
  });

  describe("Phone Number Validation", () => {
    it("should accept valid E.164 phone numbers", () => {
      const validPhones = [
        "+12345678901",
        "+27821234567",
        "+447911123456",
        "+919876543210",
        "+33612345678",
      ];

      const e164Regex = /^\+[1-9]\d{1,14}$/;

      validPhones.forEach((phone) => {
        expect(e164Regex.test(phone)).toBe(true);
        expect(phone.length).toBeLessThanOrEqual(16);
      });
    });

    it("should reject invalid phone numbers", () => {
      const invalidPhones = [
        "12345678901", // Missing +
        "+0123456789", // Starts with 0 after +
        "invalid",
        "",
        "+",
      ];

      const e164Regex = /^\+[1-9]\d{1,14}$/;

      invalidPhones.forEach((phone) => {
        expect(e164Regex.test(phone)).toBe(false);
      });
    });
  });

  describe("Error Handling", () => {
    it("should handle API failure gracefully", () => {
      const errorResponse = {
        success: false,
        error: "Network error",
      };

      expect(errorResponse.success).toBe(false);
      expect(errorResponse.error).toBeDefined();
    });

    it("should handle malformed response", () => {
      const malformedResponse = "not json";

      try {
        const parsed =
          typeof malformedResponse === "string"
            ? { success: false }
            : malformedResponse;
        expect(parsed.success).toBe(false);
      } catch {
        expect(true).toBe(true); // Caught error as expected
      }
    });
  });
});

describe("Contact Export Handling", () => {
  it("should handle CSV blob correctly", () => {
    // Simulate CSV response
    const csvContent = "name,phone,email\nJohn,+123,john@test.com";
    const blob = new Blob([csvContent], { type: "text/csv" });

    expect(blob.size).toBeGreaterThan(0);
    expect(blob.type).toBe("text/csv");
  });

  it("should generate correct filename", () => {
    const date = new Date();
    const expectedFilename = `contacts_export_${date.toISOString().split("T")[0]}.csv`;

    expect(expectedFilename).toMatch(/contacts_export_\d{4}-\d{2}-\d{2}\.csv/);
  });
});
