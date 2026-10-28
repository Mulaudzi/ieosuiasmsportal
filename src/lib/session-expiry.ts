import { safeAuthDestination } from './central-auth';

/** Expire only the identity which made the failed request; preserve form recovery. */
export function expireSession(requestToken: string | null): void {
  if (!requestToken || localStorage.getItem('auth_token') !== requestToken) return;
  const admin = /^\/guymhan(?:\/|$)/.test(window.location.pathname);
  const destination = window.location.pathname + window.location.search;
  if (!admin && safeAuthDestination(destination) === destination) {
    sessionStorage.setItem('redirectAfterLogin', destination);
  }
  localStorage.removeItem('auth_token');
  localStorage.removeItem('auth_user');
  localStorage.removeItem('auth_token_issued');
  sessionStorage.removeItem('admin_session_timestamp');
  sessionStorage.setItem('sms_session_expired', '1');
  window.dispatchEvent(new CustomEvent('auth:session-expired'));
  window.location.replace(admin ? '/guymhan/login' : '/login');
}
