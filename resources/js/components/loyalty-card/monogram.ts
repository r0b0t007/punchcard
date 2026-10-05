/** A business's monogram: the first character of its name, upper-cased (any script). */
export function monogramOf(businessName: string): string {
    return (Array.from(businessName.trim())[0] ?? '').toUpperCase();
}
