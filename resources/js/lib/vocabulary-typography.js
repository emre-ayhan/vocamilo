// Detect the content script, not the surrounding pool or interface direction.
export function hasArabicScript(text) {
 return /\p{Script=Arabic}/u.test(String(text ?? ''));
}
