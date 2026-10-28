export interface PhoneAnalysis {
  normalized: string | null;
  proposed: string;
  reason: string;
}

export interface PhoneDecision {
  action: "accept" | "reject";
  original: string;
  value?: string;
}

// Import-only rules. Never infer missing subscriber digits or alter saved contacts.
export function analyzeSouthAfricanPhone(input: string): PhoneAnalysis {
  const raw = input.trim();
  const value = raw.replace(/[\s().-]/g, "");
  const result = (normalized: string | null, proposed: string, reason: string) => ({ normalized, proposed, reason });
  if (!raw) return result(null, "", "The phone number is missing.");
  if (!/^[+\d\s().-]+$/.test(raw)) return result(null, "", "Contains letters or unsupported characters. Check the original number.");
  if (/^0[678]\d{8}$/.test(value)) return result("+27" + value.slice(1), "", "");
  if (/^\+?27[678]\d{8}$/.test(value)) return result("+" + value.replace(/^\+/, ""), "", "");
  if (/^[678]\d{8}$/.test(value)) return result(null, "0" + value, "The leading zero appears to be missing.");
  if (/^\+?270[678]\d{8}$/.test(value)) return result(null, "0" + value.replace(/^\+?270/, ""), "The local zero was kept after country code 27.");
  if (/^0027[678]\d{8}$/.test(value)) return result(null, "0" + value.slice(4), "Replace the international dialling prefix 00 with the South African format.");
  return result(null, "", "Use a South African mobile number: 10 digits starting with 06, 07 or 08, or 27 followed by the 9-digit mobile number. We cannot safely guess missing digits.");
}

export function resolvedImportPhone(original: string, decision?: PhoneDecision): string | null {
  const analysis = analyzeSouthAfricanPhone(original);
  if (analysis.normalized) return analysis.normalized;
  if (!decision || decision.original !== original || decision.action === "reject") return null;
  return analyzeSouthAfricanPhone(decision.value ?? "").normalized;
}
