/** The same display URL vocabulary as SafeUrlRule on the server. */
export function safeUrl(value: unknown): string {
    if (typeof value !== 'string' || /[\x00-\x20\x7f\\]/.test(value)) return '';
    if (value.startsWith('/') && !value.startsWith('//')) return value;
    try { const url = new URL(value); return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? value : ''; }
    catch { return ''; }
}
