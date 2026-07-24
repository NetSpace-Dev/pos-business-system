// Generates sequential, human-friendly reference numbers like INV-2026-0001
export function generateDocNumber(prefix: string, sequence: number): string {
  const year = new Date().getFullYear();
  const padded = String(sequence).padStart(4, '0');
  return `${prefix}-${year}-${padded}`;
}
