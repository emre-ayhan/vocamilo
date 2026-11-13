export async function postSession(url, payload) {
    const token = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch(url, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', ...(token ? {'X-XSRF-TOKEN': decodeURIComponent(token)} : {}) },
        body: JSON.stringify(payload),
    });
    if (!response.ok) throw new Error(response.status === 419 ? 'Your sign-in session expired. Keep this page open and sign in in another tab, then retry.' : 'The session could not be saved. Check your connection and try again.');
    return response.json();
}

export function sessionAnswers(results, mode) {
    return results.map(result => ({
        word_id: result.id, answer: result.answer,
        ...(mode === 'flashcards' ? { known: result.correct } : {}),
        self_assessed: mode === 'flashcards' ? false : !!result.self_assessed,
    }));
}
