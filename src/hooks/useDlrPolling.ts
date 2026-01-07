import { useEffect, useRef, useCallback } from 'react';
import { getCampaign } from '@/lib/api';

interface UseDlrPollingOptions {
  campaignId: string;
  enabled?: boolean;
  interval?: number;
  onUpdate?: (data: any) => void;
  onError?: (error: Error) => void;
}

export function useDlrPolling({
  campaignId,
  enabled = true,
  interval = 10000,
  onUpdate,
  onError,
}: UseDlrPollingOptions) {
  const intervalRef = useRef<NodeJS.Timeout | null>(null);
  const isPollingRef = useRef(false);

  const fetchCampaign = useCallback(async () => {
    if (isPollingRef.current) return;
    
    isPollingRef.current = true;
    try {
      const response = await getCampaign(campaignId);
      if (response.success && response.data) {
        onUpdate?.(response.data);
      }
    } catch (error) {
      onError?.(error as Error);
    } finally {
      isPollingRef.current = false;
    }
  }, [campaignId, onUpdate, onError]);

  useEffect(() => {
    if (!enabled || !campaignId) return;

    // Initial fetch
    fetchCampaign();

    // Set up polling
    intervalRef.current = setInterval(fetchCampaign, interval);

    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    };
  }, [enabled, campaignId, interval, fetchCampaign]);

  const stopPolling = useCallback(() => {
    if (intervalRef.current) {
      clearInterval(intervalRef.current);
      intervalRef.current = null;
    }
  }, []);

  const startPolling = useCallback(() => {
    if (!intervalRef.current && enabled) {
      intervalRef.current = setInterval(fetchCampaign, interval);
    }
  }, [enabled, interval, fetchCampaign]);

  return { stopPolling, startPolling, refetch: fetchCampaign };
}
