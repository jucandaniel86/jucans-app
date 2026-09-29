export const AVATAR_SPRITE = {
  width: 1731,
  height: 909,
  columns: 4,
  rows: 2,
} as const

export interface AvatarOption {
  id: string
  row: number
  column: number
  label: string
}

export const AVATARS: AvatarOption[] = [
  { id: 'avatar-01', row: 0, column: 0, label: 'Avatar 1' },
  { id: 'avatar-02', row: 0, column: 1, label: 'Avatar 2' },
  { id: 'avatar-03', row: 0, column: 2, label: 'Avatar 3' },
  { id: 'avatar-04', row: 0, column: 3, label: 'Avatar 4' },
  { id: 'avatar-05', row: 1, column: 0, label: 'Avatar 5' },
  { id: 'avatar-06', row: 1, column: 1, label: 'Avatar 6' },
  { id: 'avatar-07', row: 1, column: 2, label: 'Avatar 7' },
  { id: 'avatar-08', row: 1, column: 3, label: 'Avatar 8' },
]

export function findAvatar(id: string | null): AvatarOption | null {
  return AVATARS.find((avatar) => avatar.id === id) ?? null
}

export function avatarBackgroundPosition(avatar: AvatarOption): string {
  const horizontal = (avatar.column / (AVATAR_SPRITE.columns - 1)) * 100
  return `${horizontal}% ${avatar.row === 0 ? 'top' : 'bottom'}`
}
