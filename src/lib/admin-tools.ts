export const ADMIN_TOOL_TABS = ['users', 'scheduled', 'activity', 'contact-emails', 'settings', 'health'] as const;
export type AdminToolTab = typeof ADMIN_TOOL_TABS[number];

export interface CronJob {
  job_name: string;
  last_run_at?: string | null;
  last_seen_at?: string | null;
  run_count?: number | null;
}

/** A malformed scheduler row must not take down the admin tools page. */
export function cronJobsFromResponse(jobs: unknown): CronJob[] {
  return Array.isArray(jobs) ? jobs.filter((job): job is CronJob =>
    Boolean(job && typeof job === 'object' && typeof job.job_name === 'string' && job.job_name.trim())) : [];
}

export function adminToolTab(search: string): AdminToolTab {
  const value = new URLSearchParams(search).get('tab');
  return ADMIN_TOOL_TABS.includes(value as AdminToolTab) ? value as AdminToolTab : 'users';
}

export function adminLinkActive(href: string, location: {pathname: string; search: string; hash: string}): boolean {
  const target = new URL(href, 'https://sms.ieosuia.com');
  if (target.pathname !== location.pathname) return false;
  if (target.pathname === '/guymhan') {
    return (target.searchParams.get('tab') === 'finance') === (new URLSearchParams(location.search).get('tab') === 'finance');
  }
  if (target.pathname !== '/guymhan/tools') return true;
  const tab = adminToolTab(location.search);
  if (adminToolTab(target.search) === 'contact-emails' && ['contact-emails', 'scheduled', 'activity'].includes(tab)) return true;
  if (adminToolTab(target.search) !== tab) return false;
  if (adminToolTab(target.search) === 'settings') {
    return (target.hash === '#notification-settings') === (location.hash === '#notification-settings');
  }
  return true;
}

export interface AdminLoadTask {
  key: string;
  label: string;
  run: () => Promise<void>;
}
export interface AdminLoadFailure {key: string; label: string; message: string}

/** Apply each successful response independently, with at most two concurrent requests. */
export async function loadAdminTasks(tasks: AdminLoadTask[]): Promise<AdminLoadFailure[]> {
  const failures: AdminLoadFailure[] = [];
  for (let start = 0; start < tasks.length; start += 2) {
    const batch = tasks.slice(start, start + 2);
    const results = await Promise.allSettled(batch.map(task => task.run()));
    results.forEach((result, index) => {
      if (result.status === 'rejected') failures.push({
        key: batch[index].key, label: batch[index].label,
        message: result.reason instanceof Error ? result.reason.message : 'This section could not be loaded.',
      });
    });
  }
  return failures;
}
