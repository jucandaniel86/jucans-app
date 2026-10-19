const units: Record<string, { group: 'mass' | 'volume'; factor: number }> = {
  milligram: { group: 'mass', factor: 0.001 },
  gram: { group: 'mass', factor: 1 },
  kilogram: { group: 'mass', factor: 1000 },
  milliliter: { group: 'volume', factor: 1 },
  liter: { group: 'volume', factor: 1000 },
  teaspoon: { group: 'volume', factor: 5 },
  tablespoon: { group: 'volume', factor: 15 },
  cup: { group: 'volume', factor: 250 },
}

export function compatibleUnits(from: string): string[] {
  const source = units[from]
  if (!source) return []
  return Object.keys(units).filter((unit) => unit !== from && units[unit]?.group === source.group)
}

export function convertUnit(value: number, from: string, to: string): number | null {
  const source = units[from]
  const target = units[to]
  if (!source || !target || source.group !== target.group || !Number.isFinite(value) || value < 0) {
    return null
  }

  const converted = (value * source.factor) / target.factor
  if (!Number.isFinite(converted)) return null

  // Six significant digits keep small quantities without floating-point noise.
  return Number(converted.toPrecision(6))
}
