// public/js/auth.js
// WebAuthn / Passkeys client-side authentication

function bufferToBase64Url(buffer) {
    const bytes  = Array.from(new Uint8Array(buffer));
    const binary = bytes.map(b => String.fromCharCode(b)).join('');
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function base64UrlToBuffer(base64url) {
    let base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    base64    += '='.repeat((4 - base64.length % 4) % 4);
    const binary = atob(base64);
    return Uint8Array.from(binary, c => c.charCodeAt(0)).buffer;
}

async function loginWithPasskey() {
    if (!navigator.credentials) {
        throw new Error('Votre navigateur ne supporte pas les Passkeys.');
    }

    const optionsRes = await fetch('/api/auth/login/options', { method: 'POST' });
    if (!optionsRes.ok) {
        const err = await optionsRes.json().catch(() => ({}));
        throw new Error(err.error || 'Impossible d\'obtenir les options de connexion.');
    }
    const options = await optionsRes.json();

    let assertion;
    try {
        assertion = await navigator.credentials.get({
            publicKey: {
                ...options,
                challenge: base64UrlToBuffer(options.challenge),
                allowCredentials: (options.allowCredentials || []).map(c => ({
                    ...c,
                    id: base64UrlToBuffer(c.id)
                }))
            }
        });
    } catch (domErr) {
        if (domErr.name === 'NotAllowedError') {
            throw new Error('Opération annulée ou expirée. Réessayez.');
        }
        throw new Error('Erreur biométrique : ' + domErr.message);
    }

    const verifyRes = await fetch('/api/auth/login/verify', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            credential: {
                id:    assertion.id,
                rawId: bufferToBase64Url(assertion.rawId),
                response: {
                    clientDataJSON:    bufferToBase64Url(assertion.response.clientDataJSON),
                    authenticatorData: bufferToBase64Url(assertion.response.authenticatorData),
                    signature:         bufferToBase64Url(assertion.response.signature),
                    userHandle:        assertion.response.userHandle
                                       ? bufferToBase64Url(assertion.response.userHandle)
                                       : null
                },
                type: assertion.type
            }
        })
    });

    const result = await verifyRes.json();

    if (!verifyRes.ok || !result.token) {
        throw new Error(result.error || 'Échec de la vérification côté serveur.');
    }

    // Stocker le JWT et le refresh token
    localStorage.setItem('jwt_token',     result.token);
    localStorage.setItem('refresh_token', result.refresh_token ?? '');

    // ── CORRECTION ──────────────────────────────────────────────────
    // Stocker le vrai username (ex: "Ali Ben Ahmed") séparément.
    // Le payload JWT contient seulement l'email dans "username"
    // (comportement par défaut de LexikJWT).
    // La réponse du serveur renvoie result.user.username = vrai prénom.
    if (result.user && result.user.username) {
        localStorage.setItem('display_name', result.user.username);
    }
    // ────────────────────────────────────────────────────────────────

    window.location.href = '/';
}

async function registerPasskey(email, displayName) {
    if (!navigator.credentials) {
        throw new Error('Votre navigateur ne supporte pas WebAuthn.');
    }

    const optionsRes = await fetch('/api/auth/register/options', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, displayName })
    });
    if (!optionsRes.ok) {
        const err = await optionsRes.json().catch(() => ({}));
        throw new Error(err.error || 'Options d\'enregistrement indisponibles.');
    }
    const options = await optionsRes.json();

    let credential;
    try {
        credential = await navigator.credentials.create({
            publicKey: {
                ...options,
                challenge: base64UrlToBuffer(options.challenge),
                user: {
                    ...options.user,
                    id: base64UrlToBuffer(options.user.id)
                },
                excludeCredentials: (options.excludeCredentials || []).map(c => ({
                    ...c,
                    id: base64UrlToBuffer(c.id)
                }))
            }
        });
    } catch (domErr) {
        if (domErr.name === 'NotAllowedError') {
            throw new Error('Opération annulée ou expirée. Réessayez.');
        }
        throw new Error('Erreur lors de la création de la clé : ' + domErr.message);
    }

    const verifyRes = await fetch('/api/auth/register/verify', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            email,
            credential: {
                id:    credential.id,
                rawId: bufferToBase64Url(credential.rawId),
                response: {
                    clientDataJSON:    bufferToBase64Url(credential.response.clientDataJSON),
                    attestationObject: bufferToBase64Url(credential.response.attestationObject)
                },
                type: credential.type,
                clientExtensionResults: credential.getClientExtensionResults()
            }
        })
    });

    const result = await verifyRes.json();
    if (!verifyRes.ok || !result.token) {
        throw new Error(result.error || 'Échec de l\'enregistrement côté serveur.');
    }

    localStorage.setItem('jwt_token',     result.token);
    localStorage.setItem('refresh_token', result.refresh_token ?? '');

    // Stocker aussi le username lors de l'inscription
    if (result.user && result.user.username) {
        localStorage.setItem('display_name', result.user.username);
    }

    return result;
}

function authFetch(url, options = {}) {
    const token   = localStorage.getItem('jwt_token');
    const headers = {
        ...(options.headers || {}),
        'Authorization': token ? `Bearer ${token}` : ''
    };
    return fetch(url, { ...options, headers });
}

async function refreshJwtToken() {
    const refresh = localStorage.getItem('refresh_token');
    if (!refresh) return false;

    const res = await fetch('/api/token/refresh', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ refresh_token: refresh })
    });

    if (!res.ok) {
        localStorage.removeItem('jwt_token');
        localStorage.removeItem('refresh_token');
        localStorage.removeItem('display_name');
        return false;
    }

    const data = await res.json();
    localStorage.setItem('jwt_token', data.token);
    if (data.refresh_token) {
        localStorage.setItem('refresh_token', data.refresh_token);
    }
    return true;
}