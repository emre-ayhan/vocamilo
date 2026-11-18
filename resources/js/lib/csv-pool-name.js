// Read the first CSV record, including quoted commas and escaped quotes.
export function csvPoolName(text) {
    const fields = [];
    let value = '', quoted = false;
    const source = text.replace(/^\uFEFF/, '');
    for (let i = 0; i <= source.length; i++) {
        const char = source[i];
        if (char === '"') {
            if (quoted && source[i + 1] === '"') { value += '"'; i++; }
            else quoted = !quoted;
        } else if (!quoted && (char === ',' || char === '\r' || char === '\n' || char === undefined)) {
            fields.push(value.trim()); value = '';
            if (char !== ',') break;
        } else if (char !== undefined) value += char;
    }
    const name = fields[0] ?? '';
    if (quoted || !name || [...name].length > 150 || fields.slice(1).some(Boolean)) {
        throw new Error('The first CSV row must contain only a pool name, up to 150 characters.');
    }
    return name;
}
