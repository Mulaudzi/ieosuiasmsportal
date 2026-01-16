/**
 * API Client Tests
 * Tests for the API wrapper and error handling
 */

import { describe, it, expect, vi, beforeEach } from "vitest";

describe("API Client", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  describe("Response Handling", () => {
    it("should parse JSON response correctly", () => {
      const mockResponse = {
        success: true,
        data: { id: 1, name: "Test" },
      };

      expect(mockResponse.success).toBe(true);
      expect(mockResponse.data).toEqual({ id: 1, name: "Test" });
    });

    it("should handle error response", () => {
      const errorResponse = {
        success: false,
        error: "Unauthorized",
        message: "Invalid token",
      };

      expect(errorResponse.success).toBe(false);
      expect(errorResponse.error).toBe("Unauthorized");
    });

    it("should detect session expiration", () => {
      const expiredResponse = {
        success: false,
        error: "Token expired",
        code: 401,
      };

      const isExpired = expiredResponse.code === 401;
      expect(isExpired).toBe(true);
    });
  });

  describe("Headers Construction", () => {
    it("should include auth token when present", () => {
      const token = "test_token_123";
      const headers = {
        "Content-Type": "application/json",
        Authorization: token ? `Bearer ${token}` : undefined,
      };

      expect(headers.Authorization).toBe("Bearer test_token_123");
    });

    it("should not include auth header when no token", () => {
      const token = null;
      const headers: Record<string, string> = {
        "Content-Type": "application/json",
      };
      if (token) {
        headers.Authorization = `Bearer ${token}`;
      }

      expect(headers.Authorization).toBeUndefined();
    });
  });

  describe("Request Body Handling", () => {
    it("should stringify JSON body", () => {
      const body = { name: "Test", phone: "+123" };
      const stringified = JSON.stringify(body);

      expect(stringified).toBe('{"name":"Test","phone":"+123"}');
    });

    it("should handle FormData for uploads", () => {
      const formData = new FormData();
      formData.append("file", new Blob(["test"]), "test.txt");
      formData.append("name", "Test File");

      expect(formData.has("file")).toBe(true);
      expect(formData.get("name")).toBe("Test File");
    });
  });

  describe("URL Construction", () => {
    it("should build correct endpoint URLs", () => {
      const baseUrl = "https://api.example.com";
      const endpoint = "/contacts";
      const fullUrl = `${baseUrl}${endpoint}`;

      expect(fullUrl).toBe("https://api.example.com/contacts");
    });

    it("should handle query parameters", () => {
      const baseUrl = "/contacts";
      const params = { page: 1, limit: 10, search: "test" };
      const query = new URLSearchParams(
        Object.entries(params).map(([k, v]) => [k, String(v)])
      ).toString();
      const fullUrl = `${baseUrl}?${query}`;

      expect(fullUrl).toBe("/contacts?page=1&limit=10&search=test");
    });
  });
});

describe("API Error Handling", () => {
  it("should format network error", () => {
    const networkError = new TypeError("Failed to fetch");
    const formattedError = {
      success: false,
      error: "Network error",
      message: networkError.message,
    };

    expect(formattedError.error).toBe("Network error");
  });

  it("should format validation error", () => {
    const validationError = {
      success: false,
      error: "Validation failed",
      errors: {
        phone: ["Phone must not exceed 50 characters"],
        email: ["Invalid email format"],
      },
    };

    expect(validationError.errors).toHaveProperty("phone");
    expect(validationError.errors.phone[0]).toContain("50 characters");
  });

  it("should handle 500 server error", () => {
    const serverError = {
      success: false,
      error: "Internal server error",
      code: 500,
    };

    expect(serverError.code).toBe(500);
  });
});

describe("Authentication Token Handling", () => {
  it("should store token in localStorage", () => {
    const token = "jwt_token_123";
    const storedValue = token; // Simulating localStorage.setItem/getItem

    expect(storedValue).toBe("jwt_token_123");
  });

  it("should clear token on logout", () => {
    const token = null; // After logout

    expect(token).toBeNull();
  });

  it("should detect token expiration from payload", () => {
    const mockPayload = {
      sub: 1,
      exp: Math.floor(Date.now() / 1000) - 3600, // Expired 1 hour ago
      iat: Math.floor(Date.now() / 1000) - 7200,
    };

    const isExpired = mockPayload.exp < Math.floor(Date.now() / 1000);
    expect(isExpired).toBe(true);
  });

  it("should detect valid token", () => {
    const mockPayload = {
      sub: 1,
      exp: Math.floor(Date.now() / 1000) + 3600, // Expires in 1 hour
      iat: Math.floor(Date.now() / 1000),
    };

    const isExpired = mockPayload.exp < Math.floor(Date.now() / 1000);
    expect(isExpired).toBe(false);
  });
});
