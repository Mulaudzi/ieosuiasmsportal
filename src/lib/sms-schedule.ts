/** Convert the device-local picker value into an unambiguous instant. */
export function smsSubmissionTiming(schedule: string, now = Date.now()): {send_now: boolean; scheduled_at: string | null} {
  const value = schedule.trim();
  if (!value) return {send_now: true, scheduled_at: null};
  const parts = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(value);
  const date = new Date(value);
  if (!parts || !Number.isFinite(date.getTime()) ||
      date.getFullYear() !== Number(parts[1]) || date.getMonth() + 1 !== Number(parts[2]) ||
      date.getDate() !== Number(parts[3]) || date.getHours() !== Number(parts[4]) ||
      date.getMinutes() !== Number(parts[5]) || date.getSeconds() !== Number(parts[6] ?? 0)) {
    throw new Error('Choose a valid schedule date and time.');
  }
  if (date.getTime() <= now) throw new Error('Choose a schedule time in the future, or clear it to send now.');
  return {send_now: false, scheduled_at: date.toISOString()};
}
