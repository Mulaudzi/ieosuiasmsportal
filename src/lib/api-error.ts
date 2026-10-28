export type ApiFailureKind = 'http' | 'permission' | 'hosting-blocked' | 'format';

export class ApiError extends Error {
  constructor(message: string, public readonly status: number, public readonly kind: ApiFailureKind = 'http') {
    super(message); this.name = 'ApiError';
  }
}

export const hostingBlockMessage = 'Hosting security blocked this request. Automatic retries are paused. Please contact support to review Imunify360 protection for this site, then retry.';

export function isHostingBlock(body: string): boolean {
  return /imunify360|access denied by .*bot.protection|<title[^>]*>\s*One moment, please/i.test(body);
}

export function isAccessBlocked(error: unknown): error is ApiError {
  return error instanceof ApiError && (error.status === 401 || error.status === 403 || error.kind === 'hosting-blocked');
}

/** Do not keep refetching an access rejection on every timer, focus or reconnect. */
export const guardedReadPolling = {
  retry: (failures: number, error: unknown) => !isAccessBlocked(error) && failures < 3,
  refetchInterval: (query: { state: { error: unknown } }) => isAccessBlocked(query.state.error) ? false as const : 30000,
  refetchOnWindowFocus: (query: { state: { error: unknown } }) => !isAccessBlocked(query.state.error),
  refetchOnReconnect: (query: { state: { error: unknown } }) => !isAccessBlocked(query.state.error),
};

export function serverFailureMessage(status: number): string {
  if (status === 507) return 'The server reported a storage or resource limit. Please try again later or contact support.';
  if (status === 503) return 'The service is temporarily unavailable. Please try again shortly.';
  return `A server error prevented this request from completing (${status}). Please try again.`;
}
