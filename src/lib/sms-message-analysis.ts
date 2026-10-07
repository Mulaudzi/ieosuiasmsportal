// Mirrors api/domain/SmsSegmentCalculator.php. Server preview remains authoritative,
// especially after per-recipient personalisation. Covered by cross-language tests.
const BASIC = "@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
const EXTENSION = "^{}\\[~]|€";

export function analyzeSmsMessage(message: string) {
  const characters = Array.from(message);
  const unicodeCharacters = [...new Set(characters.filter(char => !BASIC.includes(char) && !EXTENSION.includes(char)))];
  const encoding = unicodeCharacters.length ? 'ucs2' : 'gsm7';
  const unitCount = encoding === 'ucs2' ? message.length : characters.reduce((sum, char) => sum + (EXTENSION.includes(char) ? 2 : 1), 0);
  const singleLimit = encoding === 'ucs2' ? 70 : 160;
  const multipartLimit = encoding === 'ucs2' ? 67 : 153;
  const segmentCount = unitCount === 0 ? 0 : unitCount <= singleLimit ? 1 : Math.ceil(unitCount / multipartLimit);
  return {
    encoding, character_count: characters.length, unit_count: unitCount, segment_count: segmentCount,
    unicodeCharacters, remainingUnits: (segmentCount <= 1 ? singleLimit : segmentCount * multipartLimit) - unitCount,
  };
}

// Match PHP trim(), including preserving non-breaking spaces that require Unicode.
export const trimSmsText = (text: string) => {
  const phpTrimCharacters = ' \t\n\r\0\x0B';
  let start = 0;
  let end = text.length;
  while (start < end && phpTrimCharacters.includes(text[start])) start++;
  while (end > start && phpTrimCharacters.includes(text[end - 1])) end--;
  return text.slice(start, end);
};

export function composeSmsMessage(content: string, includeFooter: boolean, footer: string) {
  const body = trimSmsText(content);
  const suffix = trimSmsText(footer);
  if (!body || !includeFooter || !suffix || body.endsWith(suffix)) return body;
  return body + '\n' + suffix;
}

export function useStraightQuotes(text: string) {
  return text.replace(/[\u2018\u2019]/g, "'").replace(/[\u201c\u201d]/g, '"');
}
