/* Thinktool yönetim paneli — bağımlılıksız küçük bileşenler */
(() => {
  const $ = (sel, root = document) => root.querySelector(sel)
  const $$ = (sel, root = document) => [...root.querySelectorAll(sel)]
  const csrf = document.body.dataset.csrf || ''
  const el = (tag, attrs = {}, ...children) => {
    const node = document.createElement(tag)
    for (const [k, v] of Object.entries(attrs)) {
      if (k === 'class') node.className = v
      else if (k === 'text') node.textContent = v
      else if (k.startsWith('on')) node.addEventListener(k.slice(2), v)
      else if (v !== false && v != null) node.setAttribute(k, v === true ? '' : v)
    }
    for (const c of children.flat()) if (c != null) node.append(c instanceof Node ? c : document.createTextNode(String(c)))
    return node
  }
  /** replaceChildren boş (null) öğeleri "null" metnine çevirir; önce süzülür. */
  const fill = (root, ...nodes) => root.replaceChildren(...nodes.flat().filter((n) => n != null && n !== false))
  const hiddenInput = (name, root) => {
    const form = root.closest('form') || document
    return form.querySelector(`input[type=hidden][name="${name}"]`)
  }
  const readJson = (input, fallback) => {
    try {
      const v = JSON.parse(input?.value || '')
      return v ?? fallback
    } catch {
      return fallback
    }
  }
  const media = window.TT_MEDIA || {}

  /* Menü (mobil) */
  $('[data-menu-toggle]')?.addEventListener('click', () => $('[data-menu]')?.classList.toggle('open'))

  /* Satıra tıklayınca ayrıntıya git */
  document.addEventListener('click', (e) => {
    const row = e.target.closest('tr[data-href]')
    if (row && !e.target.closest('a, button, input, select, textarea, label')) location.href = row.dataset.href
  })

  /* Onay isteyen düğmeler */
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-confirm]')
    if (btn && !confirm(btn.dataset.confirm)) {
      e.preventDefault()
      e.stopPropagation()
    }
  }, true)

  /* ───────── Görsel seçici ───────── */
  async function pickMedia({ multiple = false } = {}) {
    return new Promise((resolve) => {
      const selected = new Set()
      const grid = el('div', { class: 'modal-grid' }, el('p', { class: 'muted', text: 'Yükleniyor…' }))
      const status = el('span', { class: 'muted small' })
      const close = (result) => {
        backdrop.remove()
        resolve(result)
      }
      const upload = el('input', { type: 'file', accept: 'image/*', multiple: true, hidden: true })
      const render = (items) => {
        fill(grid, ...items.map((m) => {
          media[m.id] = { url: m.url, alt: m.alt }
          const b = el('button', { type: 'button', title: m.alt || m.file }, el('img', { src: m.url, alt: '', loading: 'lazy' }))
          b.addEventListener('click', () => {
            if (!multiple) {
              close([m.id])
              return
            }
            if (selected.has(m.id)) selected.delete(m.id)
            else selected.add(m.id)
            b.classList.toggle('selected', selected.has(m.id))
            status.textContent = selected.size ? `${selected.size} görsel seçildi` : ''
          })
          return b
        }))
      }
      const load = async () => render((await (await fetch('/yonetim/medya.php?json=1', { credentials: 'same-origin' })).json()).items || [])
      upload.addEventListener('change', async () => {
        if (!upload.files.length) return
        status.textContent = 'Yükleniyor…'
        const fd = new FormData()
        for (const f of upload.files) fd.append('files[]', f)
        const res = await fetch('/yonetim/medya.php?json=upload', { method: 'POST', body: fd, headers: { 'X-CSRF-Token': csrf }, credentials: 'same-origin' })
        const body = await res.json().catch(() => ({}))
        if (!body.ok) {
          status.textContent = body.error || 'Yükleme başarısız'
          return
        }
        await load()
        const ids = body.items.map((m) => m.id)
        if (!multiple) close(ids.slice(0, 1))
        else {
          ids.forEach((id) => selected.add(id))
          close([...selected])
        }
      })
      const backdrop = el('div', { class: 'modal-backdrop', onclick: (e) => e.target === backdrop && close([]) },
        el('div', { class: 'modal', role: 'dialog', 'aria-modal': 'true' },
          el('div', { class: 'modal-head' },
            el('h2', { text: multiple ? 'Görsel seçin' : 'Görsel seçin' }),
            el('label', { class: 'btn btn-secondary btn-sm' }, 'Bilgisayardan yükle', upload),
            el('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Kapat', onclick: () => close([]) })),
          el('div', { class: 'modal-body' }, grid),
          multiple ? el('div', { class: 'modal-foot' }, status, el('button', { type: 'button', class: 'btn btn-primary', text: 'Ekle', onclick: () => close([...selected]) })) : null))
      document.body.append(backdrop)
      load()
    })
  }

  const thumb = (id) => el('img', { src: media[id]?.url || '', alt: media[id]?.alt || '', loading: 'lazy' })

  /* ───────── Galeri (sıralı görsel listesi) ───────── */
  $$('[data-gallery]').forEach((root) => {
    const input = hiddenInput(root.dataset.gallery, root)
    let ids = readJson(input, []).map(Number)
    const save = () => (input.value = JSON.stringify(ids))
    const render = () => {
      root.className = 'gallery'
      fill(root, 
        ...ids.map((id, i) => el('div', { class: 'gallery-item' },
          i === 0 ? el('span', { class: 'main-tag', text: 'Ana görsel' }) : null,
          thumb(id),
          el('div', { class: 'tools' },
            el('button', { type: 'button', title: 'Sola', text: '←', onclick: () => { if (i > 0) { [ids[i - 1], ids[i]] = [ids[i], ids[i - 1]]; save(); render() } } }),
            el('button', { type: 'button', title: 'Sağa', text: '→', onclick: () => { if (i < ids.length - 1) { [ids[i + 1], ids[i]] = [ids[i], ids[i + 1]]; save(); render() } } }),
            el('button', { type: 'button', title: 'Kaldır', text: '✕', onclick: () => { ids.splice(i, 1); save(); render() } })))),
        el('button', { type: 'button', class: 'gallery-add', text: '+ Görsel ekle', onclick: async () => { ids.push(...(await pickMedia({ multiple: true })).filter((x) => !ids.includes(x))); save(); render() } }))
    }
    render()
  })

  /* ───────── Tek görsel (kapak) ───────── */
  function singleMedia(root, get, set) {
    const render = () => {
      const id = get()
      root.className = 'single-media'
      fill(root, 
        id ? thumb(id) : el('span', { class: 'muted small', text: 'Görsel yok' }),
        el('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: id ? 'Değiştir' : 'Görsel seç', onclick: async () => { const [x] = await pickMedia(); if (x) { set(x); render() } } }),
        id ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm', text: 'Kaldır', onclick: () => { set(null); render() } }) : null)
    }
    render()
  }
  $$('[data-single-media]').forEach((root) => {
    const input = hiddenInput(root.dataset.singleMedia, root)
    singleMedia(root, () => Number(input.value) || null, (v) => (input.value = v ?? ''))
  })

  /* ───────── Tekrarlanan alanlar ───────── */
  function repeater(root, rows, fields, onChange, max = 50) {
    const render = () => {
      root.className = 'repeater'
      fill(root, 
        ...rows.map((row, i) => el('div', { class: 'rep-row' },
          el('div', { class: 'rep-fields' }, ...fields.map((f) => {
            const control = f.type === 'textarea'
              ? el('textarea', { rows: 2, placeholder: f.label })
              : el('input', { placeholder: f.placeholder || f.label, 'aria-label': f.label })
            control.value = row[f.name] ?? ''
            control.addEventListener('input', () => { row[f.name] = control.value; onChange(rows) })
            if (f.width) control.style.flex = `0 1 ${f.width}`
            return control
          })),
          el('div', { class: 'rep-tools' },
            el('button', { type: 'button', title: 'Yukarı', text: '↑', onclick: () => { if (i > 0) { [rows[i - 1], rows[i]] = [rows[i], rows[i - 1]]; onChange(rows); render() } } }),
            el('button', { type: 'button', title: 'Sil', text: '✕', onclick: () => { rows.splice(i, 1); onChange(rows); render() } })))),
        rows.length < max ? el('button', { type: 'button', class: 'btn btn-ghost btn-sm rep-add', text: '+ Satır ekle', onclick: () => { rows.push(Object.fromEntries(fields.map((f) => [f.name, '']))); onChange(rows); render() } }) : null)
    }
    render()
  }
  $$('[data-repeater]').forEach((root) => {
    const input = hiddenInput(root.dataset.repeater, root)
    const rows = readJson(input, [])
    repeater(root, rows, JSON.parse(root.dataset.fields), (r) => (input.value = JSON.stringify(r)), Number(root.dataset.max) || 50)
  })

  /* ───────── Tanıtım bölümleri ───────── */
  const BLOCKS = {
    feature: 'Görselli metin',
    cards: 'Kartlar',
    gallery: 'Galeri',
    video: 'YouTube videosu',
    callout: 'Bilgi kutusu',
  }
  const blankBlock = (type) => ({
    feature: { type, eyebrow: '', heading: '', body: '', bullets: [], image: null, layout: 'image-right' },
    cards: { type, eyebrow: '', heading: '', intro: '', items: [] },
    gallery: { type, heading: '', images: [] },
    video: { type, heading: '', youtubeId: '' },
    callout: { type, text: '', tone: 'info' },
  })[type]

  $$('[data-sections]').forEach((root) => {
    const input = hiddenInput(root.dataset.sections, root)
    const blocks = readJson(input, [])
    const save = () => (input.value = JSON.stringify(blocks))
    const text = (b, key, label, area = false) => {
      const c = area ? el('textarea', { rows: 4, placeholder: label }) : el('input', { placeholder: label })
      c.value = b[key] ?? ''
      c.addEventListener('input', () => { b[key] = c.value; save() })
      return el('label', { class: 'field' }, el('span', { class: 'field-label', text: label }), c)
    }
    const render = () => {
      root.className = 'stack'
      fill(root, 
        ...blocks.map((b, i) => {
          const body = el('div', { class: 'stack' })
          if (b.type === 'feature') {
            const img = el('div')
            singleMedia(img, () => b.image, (v) => { b.image = v; save() })
            const layout = el('select', {}, ...[['image-right', 'Görsel sağda'], ['image-left', 'Görsel solda'], ['image-below', 'Görsel altta']].map(([v, l]) => el('option', { value: v, text: l, selected: b.layout === v })))
            layout.addEventListener('change', () => { b.layout = layout.value; save() })
            const bullets = el('div')
            repeater(bullets, b.bullets = b.bullets || [], [{ name: 'title', label: 'Başlık', width: '35%' }, { name: 'text', label: 'Açıklama' }], save)
            body.append(el('div', { class: 'row' }, text(b, 'eyebrow', 'Üst etiket'), text(b, 'heading', 'Başlık')), text(b, 'body', 'Metin (paragraflar arasında boş satır)', true), el('p', { class: 'field-label', text: 'Maddeler' }), bullets, el('div', { class: 'row' }, el('div', {}, el('p', { class: 'field-label', text: 'Görsel' }), img), el('label', { class: 'field' }, el('span', { class: 'field-label', text: 'Yerleşim' }), layout)))
          } else if (b.type === 'cards') {
            const items = el('div', { class: 'stack' })
            const renderItems = () => {
              fill(items, ...(b.items = b.items || []).map((it, j) => {
                const img = el('div')
                singleMedia(img, () => it.image, (v) => { it.image = v; save() })
                return el('div', { class: 'block' }, el('div', { class: 'block-head' }, el('strong', { text: `Kart ${j + 1}` }), el('div', { class: 'block-tools' }, el('button', { type: 'button', text: '✕', title: 'Sil', onclick: () => { b.items.splice(j, 1); save(); renderItems() } }))), el('div', { class: 'row' }, text(it, 'title', 'Başlık'), text(it, 'text', 'Açıklama')), img)
              }), el('button', { type: 'button', class: 'btn btn-ghost btn-sm rep-add', text: '+ Kart ekle', onclick: () => { b.items.push({ title: '', text: '', image: null }); save(); renderItems() } }))
            }
            renderItems()
            body.append(el('div', { class: 'row' }, text(b, 'eyebrow', 'Üst etiket'), text(b, 'heading', 'Başlık')), text(b, 'intro', 'Giriş metni', true), items)
          } else if (b.type === 'gallery') {
            const gal = el('div')
            const renderGal = () => {
              gal.className = 'gallery'
              fill(gal, ...(b.images = b.images || []).map((id, j) => el('div', { class: 'gallery-item' }, thumb(id), el('div', { class: 'tools' }, el('button', { type: 'button', text: '✕', onclick: () => { b.images.splice(j, 1); save(); renderGal() } })))), el('button', { type: 'button', class: 'gallery-add', text: '+ Görsel ekle', onclick: async () => { b.images.push(...await pickMedia({ multiple: true })); save(); renderGal() } }))
            }
            renderGal()
            body.append(text(b, 'heading', 'Başlık'), gal)
          } else if (b.type === 'video') {
            body.append(el('div', { class: 'row' }, text(b, 'heading', 'Başlık'), text(b, 'youtubeId', 'YouTube adresi veya video kimliği')))
          } else if (b.type === 'callout') {
            const tone = el('select', {}, el('option', { value: 'info', text: 'Bilgi', selected: b.tone !== 'warning' }), el('option', { value: 'warning', text: 'Uyarı', selected: b.tone === 'warning' }))
            tone.addEventListener('change', () => { b.tone = tone.value; save() })
            body.append(text(b, 'text', 'Metin', true), el('label', { class: 'field' }, el('span', { class: 'field-label', text: 'Tür' }), tone))
          }
          return el('div', { class: 'block' },
            el('div', { class: 'block-head' },
              el('strong', { text: `${i + 1}. ${BLOCKS[b.type] || b.type}` }),
              el('div', { class: 'block-tools' },
                el('button', { type: 'button', title: 'Yukarı', text: '↑', onclick: () => { if (i > 0) { [blocks[i - 1], blocks[i]] = [blocks[i], blocks[i - 1]]; save(); render() } } }),
                el('button', { type: 'button', title: 'Aşağı', text: '↓', onclick: () => { if (i < blocks.length - 1) { [blocks[i + 1], blocks[i]] = [blocks[i], blocks[i + 1]]; save(); render() } } }),
                el('button', { type: 'button', title: 'Sil', text: '✕', onclick: () => { if (confirm('Bölüm silinsin mi?')) { blocks.splice(i, 1); save(); render() } } }))),
            body)
        }),
        el('div', { class: 'block-add' }, el('span', { class: 'muted small', text: 'Bölüm ekle:' }), ...Object.entries(BLOCKS).map(([type, label]) => el('button', { type: 'button', class: 'btn btn-secondary btn-sm', text: `+ ${label}`, onclick: () => { blocks.push(blankBlock(type)); save(); render() } }))))
    }
    render()
  })

  /* ───────── Metin düzenleyici (Quill) ───────── */
  const initEditors = () => {
    $$('[data-richtext]').forEach((root) => {
      const input = hiddenInput(root.dataset.richtext, root)
      if (!window.Quill) {
        const ta = el('textarea', { rows: 14 })
        ta.value = input.value
        ta.addEventListener('input', () => (input.value = ta.value))
        fill(root, ta)
        return
      }
      const q = new window.Quill(root, {
        theme: 'snow',
        modules: { toolbar: [[{ header: [2, 3, false] }], ['bold', 'italic', 'underline', 'link'], [{ list: 'ordered' }, { list: 'bullet' }], ['blockquote', 'clean']] },
      })
      q.clipboard.dangerouslyPasteHTML(input.value || '')
      q.on('text-change', () => (input.value = q.root.innerHTML === '<p><br></p>' ? '' : q.root.innerHTML))
    })
  }
  if ($('[data-richtext]')) window.addEventListener('load', initEditors)

  /* Ürün fiyatı: TL önizleme */
  const price = $('input[name=priceUsd]')
  const preview = $('[data-tl-preview]')
  if (price && preview && window.TT_RATE) {
    const fmt = new Intl.NumberFormat('tr-TR', { style: 'currency', currency: 'TRY', maximumFractionDigits: 0 })
    price.addEventListener('input', () => {
      const usd = Number(price.value.replace(',', '.')) || 0
      const step = window.TT_RATE.roundTo || 1
      preview.textContent = fmt.format(Math.round((usd * window.TT_RATE.rate) / step) * step)
    })
  }
})()
