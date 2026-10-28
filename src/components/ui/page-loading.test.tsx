import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, expect, it } from "vitest";
import { AppLoadingScreen, LoadingBar, PageLoading, type PageSkeletonVariant } from "./page-loading";

afterEach(cleanup);

it.each<PageSkeletonVariant>(["overview", "contacts", "table", "form", "wizard"])("renders accessible %s placeholders without fabricated data", variant => {
  const { container } = render(<PageLoading variant={variant} label="Loading your content" />);
  expect(screen.getByRole("status", { name: "Loading your content" })).toHaveAttribute("aria-busy", "true");
  expect(container.querySelector(".page-loading-bar")).toHaveAttribute("aria-hidden", "true");
  expect(container.querySelectorAll(".animate-pulse").length).toBeGreaterThan(5);
  expect(container.querySelectorAll("button,input,a,table")).toHaveLength(0);
  expect(screen.queryByText("Loading page...")).not.toBeInTheDocument();
  expect(screen.queryByText("0")).not.toBeInTheDocument();
  expect(screen.queryByText("No contacts yet")).not.toBeInTheDocument();
});

it("does not expose navigation or private information while authentication is pending", () => {
  const { container } = render(<AppLoadingScreen />);
  expect(screen.getAllByRole("status")).toHaveLength(1);
  expect(screen.getByRole("main")).toBeInTheDocument();
  expect(container.querySelectorAll("a,button,input")).toHaveLength(0);
  expect(screen.getByRole("status").textContent).toBe("Preparing your workspace");
});

it("does not invent a completion percentage for indeterminate progress", () => {
  const { container } = render(<LoadingBar />);
  expect(container.querySelector("[aria-valuenow]")).toBeNull();
  expect(container.firstElementChild).toHaveAttribute("aria-hidden", "true");
});

it("respects reduced motion in shared skeletons", () => {
  const { container } = render(<PageLoading />);
  for (const block of container.querySelectorAll(".animate-pulse")) expect(block.classList.contains("motion-reduce:animate-none")).toBe(true);
});
