/** The IANA time zones this browser knows, with a short list as a fallback for engines that cannot enumerate them. */
const FALLBACK = ['UTC', 'Asia/Karachi', 'Asia/Dubai', 'Asia/Kolkata', 'Asia/Riyadh', 'Europe/London', 'Europe/Berlin', 'America/New_York', 'America/Chicago', 'America/Los_Angeles', 'Australia/Sydney'];

export function timeZoneNames(): string[] {
  try {
    const supported = (Intl as unknown as { supportedValuesOf?: (key: string) => string[] }).supportedValuesOf?.('timeZone');
    if (supported && supported.length > 0) {
      return supported.includes('UTC') ? supported : ['UTC', ...supported];
    }
  } catch {
    // fall through to the short list
  }
  return FALLBACK;
}

/** "UTC+05:00" for a zone right now, to help people recognise it. */
export function offsetLabel(zone: string): string {
  try {
    const part = new Intl.DateTimeFormat('en', { timeZone: zone, timeZoneName: 'longOffset' })
      .formatToParts(new Date())
      .find((p) => p.type === 'timeZoneName')?.value;
    return part === 'GMT' ? 'UTC+00:00' : (part ?? '').replace('GMT', 'UTC');
  } catch {
    return '';
  }
}

/** ISO 3166 country codes with their English names, worked out from the browser rather than a hand-kept list. */
export function countryOptions(): { value: string; label: string }[] {
  const names = new Intl.DisplayNames(['en'], { type: 'region' });
  const out: { value: string; label: string }[] = [];
  for (let a = 65; a <= 90; a += 1) {
    for (let b = 65; b <= 90; b += 1) {
      const code = String.fromCharCode(a) + String.fromCharCode(b);
      let name: string | undefined;
      try {
        name = names.of(code);
      } catch {
        name = undefined;
      }
      if (name && name !== code && !/^Unknown Region/.test(name)) out.push({ value: code, label: `${name} (${code})` });
    }
  }
  return out.sort((x, y) => x.label.localeCompare(y.label));
}
