const upperCase = "ABCDEFGHJKLMNPQRSTUVWXYZ"
const lowerCase = "abcdefghijkmnopqrstuvwxyz"
const digits = "23456789"
const symbols = "!@#$%*-_"
const allCharacters = `${upperCase}${lowerCase}${digits}${symbols}`
const credentialLength = 20

function secureIndex(length: number): number {
  const limit = Math.floor(256 / length) * length
  const bytes = new Uint8Array(1)

  do {
    crypto.getRandomValues(bytes)
  } while (bytes[0] >= limit)

  return bytes[0] % length
}

function secureCharacter(characters: string): string {
  return characters[secureIndex(characters.length)]
}

/**
 * Produces a browser-only, one-time temporary credential. Callers must show
 * it only after successful provisioning and clear it when the receipt closes.
 */
export function generateTemporaryCredential(): string {
  const characters = [
    secureCharacter(upperCase),
    secureCharacter(lowerCase),
    secureCharacter(digits),
    secureCharacter(symbols),
  ]

  while (characters.length < credentialLength) {
    characters.push(secureCharacter(allCharacters))
  }

  for (let index = characters.length - 1; index > 0; index -= 1) {
    const swapIndex = secureIndex(index + 1)
    ;[characters[index], characters[swapIndex]] = [
      characters[swapIndex],
      characters[index],
    ]
  }

  return characters.join("")
}
