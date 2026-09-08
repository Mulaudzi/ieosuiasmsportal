import { useQuery } from "@tanstack/react-query";
import { api } from "@/lib/api";

interface WalletData {
  id: number;
  balance: number;
  reserved: number;
  available: number;
  currency: string;
  sms_credits: number;
  price_per_credit: number;
}

interface WalletStats {
  balance: number;
  used_this_month: number;
  total_spent: number;
  sms_credits: number;
  price_per_credit: number;
}

export function useWallet() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["wallet"],
    queryFn: async () => {
      const response = await api.get<{ wallet: WalletData }>("/wallet");
      return ((response.data ?? response) as { wallet?: WalletData }).wallet;
    },
    staleTime: 30 * 1000, // Cache for 30 seconds
    refetchOnWindowFocus: true,
  });

  return {
    wallet: data,
    balance: data?.balance ?? 0,
    available: data?.available ?? 0,
    currency: data?.currency ?? "ZAR",
    smsCredits: data?.sms_credits ?? 0,
    pricePerCredit: data?.price_per_credit ?? 0.35,
    isLoading,
    error,
    refetch,
  };
}

export function useWalletStats() {
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ["wallet-stats"],
    queryFn: async () => {
      const response = await api.get<WalletStats>("/wallet/stats");
      return (response.data ?? response) as WalletStats;
    },
    staleTime: 30 * 1000,
    refetchOnWindowFocus: true,
  });

  return {
    balance: data?.balance ?? 0,
    usedThisMonth: data?.used_this_month ?? 0,
    totalSpent: data?.total_spent ?? 0,
    smsCredits: data?.sms_credits ?? 0,
    pricePerCredit: data?.price_per_credit ?? 0.35,
    isLoading,
    error,
    refetch,
  };
}
