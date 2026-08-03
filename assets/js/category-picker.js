/**
 * In-form category dropdown registry – keeps all pickers in sync without reload.
 */
(function () {
  'use strict';

  function slugifyCategory(raw) {
    let s = String(raw || '').trim().toLowerCase();
    s = s.replace(/\s+/g, '-');
    s = s.replace(/[^a-z0-9_-]/g, '');
    s = s.replace(/^[-_]+|[-_]+$/g, '');
    return s;
  }

  function escapeAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;');
  }

  function formatCategoryLabel(cat) {
    if (!cat) return '';
    return cat.charAt(0).toUpperCase() + cat.slice(1);
  }

  window.PGCategories = {
    /** @type {string[]} */
    list: [],

    init(initial) {
      const slugs = (initial || []).map(slugifyCategory).filter(Boolean);
      this.list = [...new Set(slugs)].sort();
    },

    slugify: slugifyCategory,

    add(raw) {
      const slug = slugifyCategory(raw);
      if (!slug) return null;
      if (!this.list.includes(slug)) {
        this.list.push(slug);
        this.list.sort();
      }
      return slug;
    },

    buildOptions(selected) {
      const normalized = slugifyCategory(selected);
      let html = '<option value="">Select category…</option>';
      this.list.forEach(cat => {
        const sel = cat === normalized ? ' selected' : '';
        html += `<option value="${escapeAttr(cat)}"${sel}>${escapeAttr(formatCategoryLabel(cat))}</option>`;
      });
      html += '<option value="__new__">+ Add new category…</option>';
      return html;
    },

    rebuildSelect(selectEl, selected) {
      if (!selectEl) return;
      const normalized = slugifyCategory(selected);
      selectEl.innerHTML = this.buildOptions(normalized);
      if (normalized && this.list.includes(normalized)) {
        selectEl.value = normalized;
      } else if (!normalized) {
        selectEl.value = '';
      }
    },

    updateAll(selectors, activeSelect, activeValue) {
      document.querySelectorAll(selectors).forEach(sel => {
        const val = sel === activeSelect
          ? activeValue
          : (sel.value === '__new__' ? '' : sel.value);
        this.rebuildSelect(sel, val);
      });
    },

    toggleCustom(selectEl, customInputSelector) {
      const picker = selectEl.closest('.pg-category-picker');
      if (!picker) return;
      const wrap = picker.querySelector('[data-custom-wrap]') || picker.querySelector('.pg-category-picker__custom');
      const input = picker.querySelector(customInputSelector || '.pg-category-custom');
      if (!wrap || !input) return;

      const isNew = selectEl.value === '__new__';
      wrap.classList.toggle('is-open', isNew);
      input.required = isNew;
      input.disabled = !isNew;
      if (!isNew) {
        input.value = '';
      } else {
        input.focus();
      }
    },

    commitFromInput(selectEl, inputEl) {
      if (!selectEl || selectEl.value !== '__new__' || !inputEl) return null;
      const slug = this.add(inputEl.value);
      if (!slug) return null;

      inputEl.value = '';
      inputEl.disabled = true;
      inputEl.required = false;

      const picker = selectEl.closest('.pg-category-picker');
      const wrap = picker?.querySelector('[data-custom-wrap]') || picker?.querySelector('.pg-category-picker__custom');
      wrap?.classList.remove('is-open');

      return slug;
    },

    commitPending(selectors) {
      let ok = true;
      document.querySelectorAll(selectors).forEach(sel => {
        if (sel.value !== '__new__') return;
        const picker = sel.closest('.pg-category-picker');
        const input = picker?.querySelector('.pg-category-custom');
        if (!input || !input.value.trim()) {
          ok = false;
          this.toggleCustom(sel, '.pg-category-custom');
          input?.focus();
          return;
        }
        const slug = this.commitFromInput(sel, input);
        if (slug) {
          this.updateAll(selectors, sel, slug);
        } else {
          ok = false;
        }
      });
      return ok;
    },

    bindForm(formEl, selectSelector) {
      if (!formEl) return;

      formEl.addEventListener('change', (e) => {
        if (e.target.matches(selectSelector)) {
          this.toggleCustom(e.target, '.pg-category-custom');
        }
      });

      formEl.addEventListener('blur', (e) => {
        if (!e.target.matches('.pg-category-custom')) return;
        const picker = e.target.closest('.pg-category-picker');
        const sel = picker?.querySelector(selectSelector);
        if (!sel || sel.value !== '__new__' || !e.target.value.trim()) return;
        const slug = this.commitFromInput(sel, e.target);
        if (slug) {
          this.updateAll(selectSelector, sel, slug);
        }
      }, true);

      formEl.addEventListener('keydown', (e) => {
        if (e.target.matches('.pg-category-custom') && e.key === 'Enter') {
          e.preventDefault();
          e.target.blur();
        }
      });
    },
  };
})();
