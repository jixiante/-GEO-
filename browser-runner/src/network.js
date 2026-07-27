export function isPrivateAddress(rawAddress) {
  const normalized = String(rawAddress ?? '').toLowerCase();
  const address = normalized.startsWith('::ffff:') ? normalized.slice(7) : normalized;
  if (address === '::1') {
    return true;
  }

  const octets = address.split('.').map((part) => Number.parseInt(part, 10));
  return octets.length === 4 && octets.every((part) => Number.isInteger(part) && part >= 0 && part <= 255) && (
    octets[0] === 10
    || octets[0] === 127
    || (octets[0] === 169 && octets[1] === 254)
    || (octets[0] === 172 && octets[1] >= 16 && octets[1] <= 31)
    || (octets[0] === 192 && octets[1] === 168)
  );
}
