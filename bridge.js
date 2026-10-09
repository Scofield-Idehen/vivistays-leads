// Connects the Vivistays Leads page to this server instead of claude.ai.
// It provides the same window.claude.use("db" | "sample" | "user") the page expects.
(function () {
  "use strict";
  async function api(action, body) {
    let r;
    try {
      r = await fetch("api.php?action=" + action, {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json", "X-Requested-With": "fetch" },
        body: JSON.stringify(body || {})
      });
    } catch (e) { throw { code: "network", message: "No connection" }; }
    if (r.status === 401) { location.reload(); throw { code: "not_signed_in" }; }
    let j = {};
    try { j = await r.json(); } catch (e) {}
    if (!r.ok || j.error) throw { code: j.code || "error", message: j.error || "Server error" };
    return j;
  }

  // Live lists: each list refreshes after every change and every 15 seconds,
  // so a colleague's changes show up without reloading.
  const subs = {};
  async function refresh(col) {
    const j = await api("list", { collection: col });
    const docs = j.docs.map(d => ({ id: d.id, data: () => d.data }));
    (subs[col] || []).forEach(s => s.cb({ docs }));
  }
  setInterval(() => {
    if (document.visibilityState !== "visible") return;
    Object.keys(subs).forEach(c => refresh(c).catch(() => {}));
  }, 15000);

  const db = {
    collection(col) {
      return {
        onSnapshot(cb, err) {
          const s = { cb };
          (subs[col] = subs[col] || []).push(s);
          refresh(col).catch(e => { if (err) err(e); });
          return () => { subs[col] = subs[col].filter(x => x !== s); };
        },
        doc(id) {
          return {
            update: async p => { await api("update", { collection: col, id, data: p }); refresh(col).catch(() => {}); },
            set: async d => { await api("set", { collection: col, id, data: d }); refresh(col).catch(() => {}); },
            delete: async () => { await api("delete", { collection: col, id }); refresh(col).catch(() => {}); }
          };
        },
        add: async d => { const j = await api("add", { collection: col, data: d }); refresh(col).catch(() => {}); return { id: j.id }; }
      };
    }
  };

  const sample = async (input, opts) => {
    const prompt = typeof input === "string" ? input : input.map(m => m.content).join("\n\n");
    const j = await api("ai", { prompt, tier: (opts && opts.modelTier) || "default" });
    return { text: j.text || "", truncated: false };
  };
  sample.json = async (input, opts) => {
    const r = await sample(input + "\n\nRespond with the JSON only, no other text.", opts);
    const m = r.text.match(/\{[\s\S]*\}/);
    if (!m) throw { code: "bad_json", message: "The AI reply wasn't readable" };
    return JSON.parse(m[0]);
  };

  const me = window.VL_USER || "user";
  const user = { id: async () => me, me: async () => ({ id: me, name: me }), isOwner: () => true, canEdit: () => true, can: () => true };

  window.claude = {
    use: async name => name === "db" ? db : name === "sample" ? (window.VL_AI ? sample : null) : name === "user" ? user : null
  };
})();
