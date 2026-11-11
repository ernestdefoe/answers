/**
 * A word-level diff for revision history: the longest common subsequence of
 * tokens (words and the spaces between them). Big texts that would cost too
 * much to compare show as one removal and one insertion instead.
 */
export type Part = { type: 'same' | 'ins' | 'del'; text: string };

export default function diff(before: string, after: string): Part[] {
  const a = before.match(/\s+|[^\s]+/g) || [];
  const b = after.match(/\s+|[^\s]+/g) || [];
  if (a.length * b.length > 2_000_000) {
    return [
      { type: 'del', text: before },
      { type: 'ins', text: after },
    ];
  }

  // lengths[i][j]: LCS length of a[i..] and b[j..], one row at a time from the end.
  const rows: Uint32Array[] = Array.from({ length: a.length + 1 }, () => new Uint32Array(b.length + 1));
  for (let i = a.length - 1; i >= 0; i--) {
    for (let j = b.length - 1; j >= 0; j--) {
      rows[i][j] = a[i] === b[j] ? rows[i + 1][j + 1] + 1 : Math.max(rows[i + 1][j], rows[i][j + 1]);
    }
  }

  const parts: Part[] = [];
  const push = (type: Part['type'], text: string) => {
    const last = parts[parts.length - 1];
    if (last && last.type === type) last.text += text;
    else parts.push({ type, text });
  };
  let i = 0;
  let j = 0;
  while (i < a.length && j < b.length) {
    if (a[i] === b[j]) {
      push('same', a[i]);
      i++;
      j++;
    } else if (rows[i + 1][j] >= rows[i][j + 1]) {
      push('del', a[i++]);
    } else {
      push('ins', b[j++]);
    }
  }
  while (i < a.length) push('del', a[i++]);
  while (j < b.length) push('ins', b[j++]);

  return parts;
}
