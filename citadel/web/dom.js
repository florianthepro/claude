// Minimal DOM builder — Trusted-Types-safe (textContent/createElement only, no innerHTML).
export function el(tag, attrs = {}, ...kids) {
  const n = document.createElement(tag)
  for (const [k, v] of Object.entries(attrs)) {
    if (v == null) continue
    if (k === 'class') n.className = v
    else if (k === 'text') n.textContent = v
    else if (k === 'value' || k === 'checked' || k === 'disabled') n[k] = v
    else if (k.startsWith('on') && typeof v === 'function') n.addEventListener(k.slice(2).toLowerCase(), v)
    else n.setAttribute(k, v)
  }
  for (const kid of kids) if (kid != null) n.append(kid)
  return n
}

export function mount(root, node) {
  root.replaceChildren(node)
}
