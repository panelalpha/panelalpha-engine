function normalisedDomain(value) {
    return value.trim().replace(/^"|"$/g, '').replace(/^\./, '').toLowerCase();
}

function sharedParents(r) {
    const declared = r.variables.pa_cookie_shared_parents;

    return typeof declared === 'string' ? declared.split(/\s+/).filter(Boolean).map(normalisedDomain) : [];
}

function scopedToHost(cookie, parents, host) {
    return cookie.replace(/;\s*domain\s*=\s*"?([^";]*)"?/gi,
        (attribute, domain) => parents.includes(normalisedDomain(domain)) ? '; Domain=' + host : attribute);
}

function hostScopedCookies(r) {
    const parents = sharedParents(r);
    const cookies = r.headersOut['Set-Cookie'];
    if (parents.length === 0 || cookies === undefined) {
        return;
    }

    const host = r.variables.host;
    r.headersOut['Set-Cookie'] = (Array.isArray(cookies) ? cookies : [cookies])
        .map((cookie) => scopedToHost(cookie, parents, host));
}

export default { hostScopedCookies };
