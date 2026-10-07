/**
 * Vitest Setup File
 * Configures testing environment with mocks and utilities
 */

import "@testing-library/jest-dom";
import { vi, beforeEach } from "vitest";

// Mock window.matchMedia
Object.defineProperty(window, "matchMedia", {
  writable: true,
  value: vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn(),
  })),
});

// Mock ResizeObserver
global.ResizeObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}));

// Mock IntersectionObserver
global.IntersectionObserver = vi.fn().mockImplementation(() => ({
  observe: vi.fn(),
  unobserve: vi.fn(),
  disconnect: vi.fn(),
}));

// Stateful browser storage keeps auth and draft recovery tests realistic.
const storedValues = new Map<string, string>();
const localStorageMock = {
  getItem: vi.fn((key: string) => storedValues.get(String(key)) ?? null),
  setItem: vi.fn((key: string, value: string) => { storedValues.set(String(key), String(value)); }),
  removeItem: vi.fn((key: string) => { storedValues.delete(String(key)); }),
  clear: vi.fn(() => { storedValues.clear(); }),
  key: vi.fn((index: number) => [...storedValues.keys()][index] ?? null),
  get length() { return storedValues.size; },
};
Object.defineProperty(window, "localStorage", {
  value: localStorageMock,
});

// Mock navigator.clipboard
Object.defineProperty(navigator, "clipboard", {
  value: {
    writeText: vi.fn().mockResolvedValue(undefined),
    readText: vi.fn().mockResolvedValue(""),
  },
  writable: true,
});

// Reset mocks between tests
beforeEach(() => {
  storedValues.clear();
  vi.clearAllMocks();
});
