/**
 * Broadcast Archive front end.
 *
 * Vanilla ES2020, no build step. Renders the schedule from the public REST
 * endpoint and runs one persistent transport per archive instance.
 *
 * The endpoint is anonymous and nonce-free on purpose: the page that embeds
 * this script lives in a full-page cache for weeks, so nothing here may depend
 * on a value that expires.
 */
(function () {
  'use strict';

  var cfg = window.BroadcastArchiveConfig;

  if (!cfg || !cfg.restUrl) {
    return;
  }

  var L = cfg.labels || {};
  var LOCALE = cfg.locale || document.documentElement.lang || 'en';
  var PLACEHOLDER = cfg.placeholder || '';
  var IS_RTL = !!cfg.isRtl;
  var REDUCED = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)').matches : false;
  var STORE_KEY = 'ba.progress.v1';
  var LAST_KEY = 'ba.last.v1';

  function formatter(options) {
    try {
      return new Intl.DateTimeFormat(LOCALE, options);
    } catch (error) {
      return new Intl.DateTimeFormat('en', options);
    }
  }

  var DATE_LONG = formatter({ weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  var DATE_CHIP = formatter({ weekday: 'short', day: 'numeric', month: 'short' });
  var DATE_SHORT = formatter({ day: 'numeric', month: 'short' });
  var DATE_NUMERIC = formatter({ day: 'numeric', month: 'long', year: 'numeric' });

  var NUMBER;
  try {
    NUMBER = new Intl.NumberFormat(LOCALE);
  } catch (error) {
    NUMBER = new Intl.NumberFormat('en');
  }

  /* ---------------------------------------------------------------------
   * Icons: authored one-stroke SVGs, one family.
   * ------------------------------------------------------------------ */

  var ICONS = {
    play: '<path d="M8 5.2v13.6L19 12z" fill="currentColor" stroke="none"/>',
    pause: '<rect x="7" y="5" width="3.4" height="14" rx="1.2" fill="currentColor" stroke="none"/><rect x="13.6" y="5" width="3.4" height="14" rx="1.2" fill="currentColor" stroke="none"/>',
    chevron: '<path d="m6 9 6 6 6-6"/>',
    download: '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>',
    close: '<path d="m6 6 12 12M18 6 6 18"/>',
    search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.4-3.4"/>',
    replay: '<path d="M4 10a8 8 0 1 1 2.3 5.7"/><path d="M4 4v6h6"/>',
    back: '<path d="M9 4 5 8l4 4"/><path d="M5 8h7a6 6 0 1 1-5.6 8.2"/>',
    forward: '<path d="m15 4 4 4-4 4"/><path d="M19 8h-7a6 6 0 1 0 5.6 8.2"/>'
  };

  function icon(name, extraClass) {
    var node = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    node.setAttribute('viewBox', '0 0 24 24');
    node.setAttribute('fill', 'none');
    node.setAttribute('stroke', 'currentColor');
    node.setAttribute('stroke-width', '1.75');
    node.setAttribute('stroke-linecap', 'round');
    node.setAttribute('stroke-linejoin', 'round');
    node.setAttribute('aria-hidden', 'true');
    node.setAttribute('focusable', 'false');
    node.setAttribute('class', 'ba-icon' + (extraClass ? ' ' + extraClass : ''));
    node.setAttribute('data-icon', name);
    node.innerHTML = ICONS[name] || '';
    return node;
  }

  /* ---------------------------------------------------------------------
   * Small helpers.
   * ------------------------------------------------------------------ */

  function el(tag, attrs, children) {
    var node = document.createElement(tag);

    if (attrs) {
      Object.keys(attrs).forEach(function (key) {
        var value = attrs[key];

        if (value === null || value === undefined || value === false) {
          return;
        }

        if (key === 'class') {
          node.className = value;
        } else if (key === 'text') {
          node.textContent = value;
        } else if (key === 'onclick') {
          node.addEventListener('click', value);
        } else if (value === true) {
          node.setAttribute(key, '');
        } else {
          node.setAttribute(key, String(value));
        }
      });
    }

    append(node, children);

    return node;
  }

  function append(parent, children) {
    if (children === null || children === undefined) {
      return;
    }

    if (!Array.isArray(children)) {
      children = [children];
    }

    children.forEach(function (child) {
      if (child === null || child === undefined || child === false) {
        return;
      }

      if (typeof child === 'string' || typeof child === 'number') {
        parent.appendChild(document.createTextNode(String(child)));
      } else {
        parent.appendChild(child);
      }
    });
  }

  function format(template, value) {
    if (typeof template !== 'string') {
      return '';
    }

    if (arguments.length > 2) {
      var args = Array.prototype.slice.call(arguments, 1);
      var index = 0;
      return template.replace(/%(\d+\$)?s/g, function (match, position) {
        if (position) {
          return String(args[parseInt(position, 10) - 1] ?? '');
        }
        return String(args[index++] ?? '');
      });
    }

    return template.replace('%s', String(value));
  }

  function normalize(value) {
    return String(value || '')
      .toLowerCase()
      .replace(/ø/g, 'o')
      .replace(/æ/g, 'ae')
      .replace(/ß/g, 'ss')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
  }

  function pad(value) {
    return value < 10 ? '0' + value : String(value);
  }

  function timecode(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));

    var hours = Math.floor(seconds / 3600);
    var minutes = Math.floor((seconds % 3600) / 60);
    var rest = seconds % 60;

    return hours > 0 ? hours + ':' + pad(minutes) + ':' + pad(rest) : minutes + ':' + pad(rest);
  }

  function humanDuration(seconds) {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));

    if (seconds < 60) {
      return '';
    }

    var hours = Math.floor(seconds / 3600);
    var minutes = Math.round((seconds % 3600) / 60);

    if (minutes === 60) {
      hours += 1;
      minutes = 0;
    }

    if (hours > 0) {
      if (minutes === 0) {
        return format(L.hours || '%s hr', hours);
      }

      return format(L.hoursMinutes || '%1$s hr %2$s min', hours, minutes);
    }

    return format(L.minutes || '%s min', minutes);
  }

  function fileSize(bytes) {
    bytes = Number(bytes) || 0;

    if (bytes < 1024 * 1024) {
      return '';
    }

    return NUMBER.format(Math.round((bytes / (1024 * 1024)) * 10) / 10) + ' MB';
  }

  function parseDate(value) {
    var parts = String(value || '').split('-');

    if (parts.length !== 3) {
      return null;
    }

    var date = new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]), 12, 0, 0);

    return isNaN(date.getTime()) ? null : date;
  }

  function dateLong(value) {
    var date = parseDate(value);
    return date ? DATE_LONG.format(date) : (L.unknownDate || '');
  }

  function dateNumeric(value) {
    var date = parseDate(value);
    return date ? DATE_NUMERIC.format(date) : (L.unknownDate || '');
  }

  function dateShort(value) {
    var date = parseDate(value);
    return date ? DATE_SHORT.format(date) : (L.unknownDate || '');
  }

  function dateChip(value) {
    var date = parseDate(value);

    if (!date) {
      return L.unknownDate || '';
    }

    var label = DATE_CHIP.format(date);
    return label.charAt(0).toUpperCase() + label.slice(1);
  }

  function itemLabel(item) {
    return [item.show || item.title, dateNumeric(item.date), item.time]
      .filter(Boolean)
      .join(', ');
  }

  function capitalizeFirst(value) {
    return value ? value.charAt(0).toUpperCase() + value.slice(1) : value;
  }

  function searchable(item) {
    var parts = [item.title, item.show, item.presenter, item.description, item.date, item.time];

    (Array.isArray(item.playlist) ? item.playlist : []).forEach(function (track) {
      parts.push(track.artist, track.title);
    });

    return normalize(parts.join(' '));
  }

  function sanitizeFilename(value) {
    return String(value || '')
      .replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '-')
      .replace(/\s+/g, ' ')
      .trim()
      .slice(0, 120);
  }

  function extension(url) {
    var match = String(url || '').split('?')[0].match(/\.([a-z0-9]{2,5})$/i);
    var ext = match ? match[1].toLowerCase() : '';

    return ['m4a', 'm4b', 'mp3', 'aac', 'ogg', 'opus', 'mp4', 'wav', 'webm'].indexOf(ext) >= 0 ? ext : 'm4a';
  }

  function mimeFor(url) {
    var match = String(url || '').split('?')[0].match(/\.([a-z0-9]{2,5})$/i);
    var ext = match ? match[1].toLowerCase() : '';
    var map = {
      jpg: 'image/jpeg',
      jpeg: 'image/jpeg',
      png: 'image/png',
      webp: 'image/webp',
      gif: 'image/gif',
      avif: 'image/avif'
    };

    return map[ext] || 'image/jpeg';
  }

  /* ---------------------------------------------------------------------
   * Playback position, remembered in the browser only.
   * ------------------------------------------------------------------ */

  var Store = {
    load: function () {
      try {
        var parsed = JSON.parse(window.localStorage.getItem(STORE_KEY) || '{}');

        if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
          return {};
        }

        var cutoff = Date.now() - 60 * 24 * 60 * 60 * 1000;
        var clean = {};

        Object.keys(parsed).forEach(function (id) {
          var entry = parsed[id];

          if (entry && typeof entry.p === 'number' && entry.p > 0 && (!entry.t || entry.t > cutoff)) {
            clean[id] = entry;
          }
        });

        return clean;
      } catch (error) {
        return {};
      }
    },

    save: function (map) {
      try {
        var ids = Object.keys(map);

        if (ids.length > 40) {
          ids.sort(function (a, b) {
            return (map[b].t || 0) - (map[a].t || 0);
          });

          ids.slice(40).forEach(function (id) {
            delete map[id];
          });
        }

        window.localStorage.setItem(STORE_KEY, JSON.stringify(map));
      } catch (error) {
        /* Storage may be unavailable or full; playback still works. */
      }
    },

    last: function () {
      try {
        return window.localStorage.getItem(LAST_KEY) || '';
      } catch (error) {
        return '';
      }
    },

    setLast: function (id) {
      try {
        window.localStorage.setItem(LAST_KEY, id);
      } catch (error) {
        /* ignore */
      }
    }
  };

  /* ---------------------------------------------------------------------
   * Archive instance.
   * ------------------------------------------------------------------ */

  function Archive(root) {
    this.root = root;
    this.uid = Math.random().toString(36).slice(2, 8);
    this.perPage = Math.max(6, Math.min(100, parseInt(root.getAttribute('data-ba-per-page'), 10) || 24));
    this.station = root.getAttribute('data-ba-station') || cfg.station || '';
    this.showSearch = root.getAttribute('data-ba-search') !== '0';
    this.showPrograms = root.getAttribute('data-ba-programs') !== '0';
    this.showPlaylist = root.getAttribute('data-ba-playlist') !== '0' && cfg.showPlaylist !== false;
    this.downloadEnabled = root.getAttribute('data-ba-download') !== '0' && cfg.downloadEnabled !== false;

    this.items = [];
    this.programs = [];
    this.query = '';
    this.program = '';
    this.day = '';
    this.visible = this.perPage;
    this.loading = false;
    this.error = null;
    this.playingId = '';
    this.playing = false;
    this.ended = false;
    this.audioError = false;
    this.duration = 0;
    this.pendingSeek = 0;
    this.audioSrc = '';
    this.rows = new Map();
    this.open = new Set();
    this.progress = Store.load();
    this.lastId = Store.last();
    this.searchTimer = null;
    this.lastStore = 0;
    this.lastUi = 0;
    this.lastMedia = 0;
    this.closeTimer = null;
    this.focusItemId = '';
    this.audio = null;
    this.transport = null;
    this.skeleton = root.querySelector('.ba-skeleton');
    this.skeletonClone = this.skeleton ? this.skeleton.cloneNode(true) : null;
  }

  Archive.prototype.init = function () {
    this.buildShell();
    this.buildTransport();
    this.bindGlobal();
    this.load(false);
  };

  /* ------------------------------- shell ------------------------------ */

  Archive.prototype.buildShell = function () {
    var self = this;

    this.toolbar = el('div', { class: 'ba-toolbar' }, [
      this.showSearch ? this.buildSearch() : null,
      this.showPrograms ? this.buildProgramSelect() : null
    ]);

    this.staleText = el('p', { class: 'ba-stale__text' });
    this.staleEl = el('div', { class: 'ba-stale', role: 'status', hidden: true }, [
      this.staleText,
      el('button', {
        type: 'button',
        class: 'ba-stale__retry',
        text: L.retry || '',
        onclick: function () {
          self.load(true);
        }
      })
    ]);
    this.resumeSlot = el('div', { class: 'ba-resume-slot' });
    this.daysEl = el('div', { class: 'ba-days', role: 'group', 'aria-label': L.dayLabel || '' });
    this.countEl = el('p', { class: 'ba-count' });
    this.clearEl = el('button', {
      type: 'button',
      class: 'ba-clear',
      text: L.clearFilters || '',
      onclick: function () {
        self.clearFilters();
      }
    });
    this.clearEl.hidden = true;

    this.metaEl = el('div', { class: 'ba-meta' }, [this.countEl, this.clearEl]);
    this.listEl = el('div', { class: 'ba-list' });
    this.moreEl = el('button', {
      type: 'button',
      class: 'ba-more',
      text: L.loadMore || '',
      onclick: function () {
        self.visible += self.perPage;
        self.renderList();
      }
    });
    this.moreEl.hidden = true;
    this.statusEl = this.root.querySelector('.ba-status') || el('p', { class: 'ba-sr-only', role: 'status' });

    this.root.insertBefore(this.toolbar, this.skeleton || this.root.firstChild);
    this.root.appendChild(this.staleEl);
    this.root.appendChild(this.resumeSlot);
    this.root.appendChild(this.daysEl);
    this.root.appendChild(this.metaEl);
    this.root.appendChild(this.listEl);
    this.root.appendChild(this.moreEl);

    if (!this.statusEl.parentNode) {
      this.root.appendChild(this.statusEl);
    }

    this.daysEl.hidden = true;
    this.metaEl.hidden = true;

    if (!this.showSearch && !this.showPrograms) {
      this.toolbar.hidden = true;
    }
  };

  Archive.prototype.buildSearch = function () {
    var self = this;

    this.searchInput = el('input', {
      type: 'search',
      class: 'ba-search__input',
      placeholder: L.searchPlaceholder || '',
      autocomplete: 'off',
      spellcheck: 'false'
    });
    this.searchInput.setAttribute('aria-label', L.searchLabel || L.searchPlaceholder || '');

    this.searchClear = el('button', {
      type: 'button',
      class: 'ba-search__clear',
      'aria-label': L.clearSearch || ''
    }, icon('close'));
    this.searchClear.hidden = true;

    this.searchClear.addEventListener('click', function () {
      self.searchInput.value = '';
      self.query = '';
      self.visible = self.perPage;
      self.open.clear();
      self.searchClear.hidden = true;
      self.renderList();
      self.searchInput.focus();
    });

    this.searchInput.addEventListener('input', function () {
      self.searchClear.hidden = '' === self.searchInput.value;

      window.clearTimeout(self.searchTimer);
      self.searchTimer = window.setTimeout(function () {
        self.query = normalize(self.searchInput.value.trim());
        self.visible = self.perPage;
        self.open.clear();
        self.renderList();
      }, 180);
    });

    this.searchInput.addEventListener('search', function () {
      self.searchClear.hidden = '' === self.searchInput.value;

      if ('' === self.searchInput.value) {
        self.query = '';
        self.renderList();
      }
    });

    return el('div', { class: 'ba-search' }, [
      el('span', { class: 'ba-search__icon' }, icon('search')),
      this.searchInput,
      this.searchClear
    ]);
  };

  Archive.prototype.buildProgramSelect = function () {
    var self = this;

    this.programSelect = el('select', { class: 'ba-select' });
    this.programSelect.setAttribute('aria-label', L.programLabel || '');
    this.programSelect.disabled = true;

    this.programSelect.addEventListener('change', function () {
      self.program = self.programSelect.value;
      self.visible = self.perPage;
      self.open.clear();
      self.renderList();
    });

    return el('div', { class: 'ba-select-wrap' }, [this.programSelect]);
  };

  /* ----------------------------- transport ---------------------------- */

  Archive.prototype.buildTransport = function () {
    var self = this;

    this.cover = el('img', { class: 'ba-transport__cover', alt: '', width: '44', height: '44', decoding: 'async' });
    this.trackTitle = el('p', { class: 'ba-transport__title' });
    this.trackMeta = el('p', { class: 'ba-transport__meta' });

    this.transportPlay = el('button', {
      type: 'button',
      class: 'ba-transport__play',
      onclick: function () {
        self.togglePlayback();
      }
    });

    this.transportSeek = el('input', {
      type: 'range',
      class: 'ba-transport__range',
      min: '0',
      max: '0',
      step: '1',
      value: '0'
    });
    this.transportSeek.setAttribute('aria-label', L.position || '');
    this.transportSeek.disabled = true;

    this.transportSeek.addEventListener('input', function () {
      self.seek(Number(self.transportSeek.value));
    });

    this.transportSeek.addEventListener('change', function () {
      self.rememberProgress();
    });

    this.currentTimeEl = el('span', { class: 'ba-time ba-time--current', text: '0:00' });
    this.totalTimeEl = el('span', { class: 'ba-time ba-time--total', text: '0:00' });

    this.transportDownload = el('a', { class: 'ba-transport__download', href: '#', download: '' });
    this.transportDownload.hidden = !this.downloadEnabled;
    this.transportDownload.setAttribute('aria-label', L.download || '');
    this.transportDownload.appendChild(icon('download'));

    this.transport = el('div', { class: 'ba-transport', role: 'region', hidden: true }, [
      el('div', { class: 'ba-transport__inner' }, [
        el('div', { class: 'ba-transport__track' }, [
          this.cover,
          el('div', { class: 'ba-transport__text' }, [this.trackTitle, this.trackMeta])
        ]),
        el('div', { class: 'ba-transport__controls' }, [
          el('button', {
            type: 'button',
            class: 'ba-skip',
            'aria-label': L.back15 || '',
            onclick: function () {
              self.skip(-15);
            }
          }, [icon('back'), el('span', { class: 'ba-skip__num', text: '15' })]),
          this.transportPlay,
          el('button', {
            type: 'button',
            class: 'ba-skip',
            'aria-label': L.forward15 || '',
            onclick: function () {
              self.skip(15);
            }
          }, [icon('forward'), el('span', { class: 'ba-skip__num', text: '15' })])
        ]),
        el('div', { class: 'ba-transport__seek' }, [
          this.currentTimeEl,
          this.transportSeek,
          this.totalTimeEl
        ]),
        el('div', { class: 'ba-transport__aside' }, [
          this.transportDownload,
          el('button', {
            type: 'button',
            class: 'ba-transport__close',
            'aria-label': L.closePlayer || '',
            onclick: function () {
              self.closeTransport();
            }
          }, [icon('close')])
        ])
      ])
    ]);

    this.transport.setAttribute('aria-label', L.player || '');
    this.transport.style.setProperty('--ba-progress', '0%');

    this.transport.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        self.closeTransport();
      }
    });

    document.body.appendChild(this.transport);
    this.syncTransportControls();

    if ('ResizeObserver' in window) {
      this.transportObserver = new ResizeObserver(function () {
        self.updateTransportSpace();
      });
      this.transportObserver.observe(this.transport);
    }
  };

  /* ---------------------------- data loading -------------------------- */

  Archive.prototype.load = function (isRetry) {
    var self = this;

    this.loading = true;
    this.error = null;
    this.staleEl.hidden = true;
    this.root.setAttribute('aria-busy', 'true');

    if (isRetry) {
      this.showSkeleton();
    }

    this.announce(L.loading || '');

    this.fetchData(!!isRetry)
      .then(function (data) {
        self.applyData(data);
      })
      .catch(function (error) {
        if (!isRetry) {
          window.setTimeout(function () {
            self.load(true);
          }, 700);
          return;
        }

        self.loading = false;
        self.error = error && error.message ? error.message : '';
        self.renderError();
        self.root.setAttribute('aria-busy', 'false');
      });
  };

  Archive.prototype.fetchData = function (bust) {
    var url = cfg.restUrl.replace(/\/?$/, '/') + 'episodes';

    if (bust) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 't=' + Date.now();
    }

    var controller = 'AbortController' in window ? new AbortController() : null;
    var timer = window.setTimeout(function () {
      if (controller) {
        controller.abort();
      }
    }, 15000);

    return window.fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      cache: bust ? 'no-store' : 'default',
      headers: { Accept: 'application/json' },
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      window.clearTimeout(timer);

      return response.json().catch(function () {
        return null;
      }).then(function (payload) {
        if (!response.ok) {
          var message = payload && payload.message;

          if (!message || typeof message !== 'string') {
            if (window.console && window.console.warn) {
              window.console.warn('Broadcast Archive: HTTP ' + response.status);
            }

            throw new Error('');
          }

          throw new Error(message.replace(/<[^>]*>/g, ''));
        }

        if (!payload || !Array.isArray(payload.items)) {
          throw new Error(L.loadErrorBody || '');
        }

        return payload;
      });
    }).catch(function (error) {
      window.clearTimeout(timer);

      if (error && (error.name === 'AbortError' || error.name === 'TypeError')) {
        throw new Error('');
      }

      throw error;
    });
  };

  Archive.prototype.applyData = function (data) {
    this.loading = false;
    this.error = null;
    this.staleEl.hidden = !data.stale;
    this.staleText.textContent = data.stale ? (L.staleNotice || '') : '';

    this.items = data.items.filter(function (item) {
      return item && item.id && item.audio;
    }).map(function (item) {
      item._search = searchable(item);
      return item;
    });

    this.programs = Array.isArray(data.programs) ? data.programs : [];

    if (this.skeleton && this.skeleton.parentNode) {
      this.skeleton.parentNode.removeChild(this.skeleton);
    }

    this.root.setAttribute('aria-busy', 'false');

    if (this.showSearch || this.showPrograms) {
      this.toolbar.hidden = false;
    }

    this.renderPrograms();
    this.renderDays();
    this.renderResume();
    this.renderList();
  };

  /* ---------------------------- rendering ----------------------------- */

  Archive.prototype.filtered = function () {
    var self = this;

    return this.items.filter(function (item) {
      if (self.program && item.show !== self.program) {
        return false;
      }

      if (self.day && item.date !== self.day) {
        return false;
      }

      if (self.query && item._search.indexOf(self.query) < 0) {
        return false;
      }

      return true;
    });
  };

  Archive.prototype.renderPrograms = function () {
    if (!this.programSelect) {
      return;
    }

    var self = this;
    var options = [el('option', { value: '', text: L.allProgrammes || '' })];

    this.programs.forEach(function (program) {
      options.push(el('option', { value: program.name, text: program.name }));
    });

    this.programSelect.replaceChildren.apply(this.programSelect, options);
    this.programSelect.disabled = false;

    var exists = this.programs.some(function (program) {
      return program.name === self.program;
    });

    if (!exists) {
      this.program = '';
    }

    this.programSelect.value = this.program;
  };

  Archive.prototype.renderDays = function () {
    var self = this;
    var seen = {};
    var days = [];

    this.items.forEach(function (item) {
      if (!item.date || seen[item.date]) {
        return;
      }

      seen[item.date] = true;
      days.push(item.date);
    });

    days.sort().reverse();

    if (this.day && days.indexOf(this.day) === -1) {
      this.day = '';
    }

    this.metaEl.hidden = false;

    if (days.length <= 1) {
      this.daysEl.hidden = true;
      return;
    }

    this.daysEl.hidden = false;

    var buttons = [this.dayButton('', L.allDays || '', this.day === '')];

    days.forEach(function (day) {
      buttons.push(self.dayButton(day, dateChip(day), self.day === day));
    });

    this.daysEl.replaceChildren.apply(this.daysEl, buttons);
  };

  Archive.prototype.dayButton = function (value, label, active) {
    var self = this;

    return el('button', {
      type: 'button',
      class: 'ba-day' + (active ? ' is-active' : ''),
      'data-day': value,
      'aria-pressed': active ? 'true' : 'false',
      text: label,
      onclick: function () {
        self.day = value;
        self.visible = self.perPage;
        self.open.clear();
        self.renderDays();
        self.renderList();
        self.focusDay(value);
      }
    });
  };

  Archive.prototype.focusDay = function (value) {
    var chips = this.daysEl.querySelectorAll('.ba-day');

    for (var i = 0; i < chips.length; i += 1) {
      if (chips[i].getAttribute('data-day') === value) {
        chips[i].focus({ preventScroll: true });
        return;
      }
    }
  };

  Archive.prototype.dateCounts = function (items) {
    var counts = {};

    items.forEach(function (item) {
      var key = item.date || '';
      counts[key] = (counts[key] || 0) + 1;
    });

    return counts;
  };

  Archive.prototype.renderList = function () {
    if (this.loading && !this.items.length) {
      return;
    }

    var filtered = this.filtered();
    var visible = filtered.slice(0, this.visible);
    var counts = this.dateCounts(filtered);
    var fragment = document.createDocumentFragment();
    var currentDate = null;
    var rows = null;

    this.rows.clear();

    if (!visible.length) {
      fragment.appendChild(this.renderEmpty());
    }

    visible.forEach(function (item) {
      var key = item.date || '';

      if (key !== currentDate) {
        var headingId = 'ba-day-' + this.uid + '-' + (key || 'unknown');
        var section = el('section', { class: 'ba-day-section', 'aria-labelledby': headingId });
        var heading = el('h2', { class: 'ba-day-section__title', id: headingId }, [
          el('span', { class: 'ba-day-section__label', text: dateLong(key) }),
          el('span', { class: 'ba-day-section__count', text: NUMBER.format(counts[key] || 0) })
        ]);

        rows = el('div', { class: 'ba-day-section__rows', role: 'list' });

        section.appendChild(heading);
        section.appendChild(rows);
        fragment.appendChild(section);
        currentDate = key;
      }

      rows.appendChild(this.createRow(item));
    }, this);

    this.listEl.replaceChildren(fragment);

    if (!this.loading) {
      this.updateMeta(filtered, visible);
    }
  };

  Archive.prototype.updateMeta = function (filtered, visible) {
    var count = filtered.length;

    var countLabel = count === 1
      ? (L.episodeCountOne || '1')
      : format(L.episodeCount || '%s', NUMBER.format(count));

    this.countEl.textContent = countLabel;

    var hasFilters = !!(this.query || this.program || this.day);
    this.clearEl.hidden = !hasFilters || count === 0;

    var remaining = count - visible.length;

    if (remaining > 0) {
      this.moreEl.hidden = false;
      this.moreEl.textContent = format(L.loadMoreCount || '%s', NUMBER.format(remaining));
    } else {
      this.moreEl.hidden = true;
    }

    if (!this.loading) {
      this.announce(count === 0 ? (L.emptyTitle || '') : countLabel);
    }
  };

  Archive.prototype.renderEmpty = function () {
    var self = this;

    return el('div', { class: 'ba-empty' }, [
      el('p', { class: 'ba-empty__title', text: L.emptyTitle || '' }),
      el('button', {
        type: 'button',
        class: 'ba-button ba-button--ghost',
        text: L.clearFilters || '',
        onclick: function () {
          self.clearFilters();
        }
      })
    ]);
  };

  Archive.prototype.createRow = function (item) {
    var self = this;
    var detailsId = 'ba-details-' + this.uid + '-' + item.id;
    var isPlaying = this.playingId === item.id;
    var detailsOpen = this.open.has(item.id);
    var parts = String(item.time || '').split(/\s*[–-]\s*/);
    var start = parts[0] || '';
    var end = parts[1] || '';

    var playButton = el('button', {
      type: 'button',
      class: 'ba-episode__play',
      onclick: function () {
        self.startItem(item);
      }
    });
    playButton.setAttribute('aria-label', format(isPlaying && this.playing ? (L.pauseItem || '') : (L.playItem || ''), itemLabel(item)));

    var moreButton = el('button', {
      type: 'button',
      class: 'ba-episode__more',
      'aria-expanded': detailsOpen ? 'true' : 'false',
      'aria-controls': detailsId
    }, icon('chevron'));
    moreButton.setAttribute('aria-label', detailsOpen ? (L.hideDetails || '') : (L.showDetails || ''));

    var timeChildren = [el('span', { class: 'ba-episode__time-start', text: start || '—' })];

    if (start && end) {
      timeChildren.push(el('span', { class: 'ba-sr-only', text: ' – ' }));
    }

    timeChildren.push(el('span', { class: 'ba-episode__time-end', text: end }));

    var timeEl = el('div', { class: 'ba-episode__time' }, timeChildren);

    var durationLabel = humanDuration(item.duration);
    var presenterLabel = item.presenter ? format(L.presentedBy || '%s', item.presenter) : '';
    var presenterShort = item.presenter || '';
    var desktopMeta = [presenterLabel, durationLabel].filter(Boolean).join(' · ');
    var mobileMeta = [item.time, presenterShort, durationLabel].filter(Boolean).join(' · ');

    var summary = String(item.description || '').replace(/\s+/g, ' ').trim();

    var body = el('div', { class: 'ba-episode__body' }, [
      el('h3', { class: 'ba-episode__title', text: item.show || item.title }),
      desktopMeta ? el('p', { class: 'ba-episode__meta ba-episode__meta--desktop', text: desktopMeta }) : null,
      mobileMeta ? el('p', { class: 'ba-episode__meta ba-episode__meta--mobile', text: mobileMeta }) : null,
      summary ? el('p', { class: 'ba-episode__excerpt', text: summary.length > 160 ? summary.slice(0, 157) + '…' : summary }) : null
    ]);

    var main = el('div', { class: 'ba-episode__main' }, [playButton, timeEl, body, moreButton]);
    var details = el('div', { class: 'ba-episode__details', id: detailsId });
    var row = el('article', { class: 'ba-episode', 'data-id': item.id, role: 'listitem' }, [main, details]);

    var progress = this.progress[item.id];

    if (progress && progress.p > 60 && item.duration > 0 && progress.p < item.duration - 30) {
      var percent = Math.max(2, Math.min(99, (progress.p / item.duration) * 100));
      var progressBar = el('span', { class: 'ba-episode__progress-bar' });

      progressBar.style.width = percent.toFixed(1) + '%';
      row.appendChild(el('span', { class: 'ba-episode__progress', 'aria-hidden': 'true' }, [progressBar]));
    }

    moreButton.addEventListener('click', function () {
      self.toggleDetails(item, row, moreButton, details);
    });

    if (detailsOpen) {
      details.appendChild(this.buildDetails(item));
      details.hidden = false;
    } else {
      details.hidden = true;
    }

    if (isPlaying) {
      row.classList.add('is-playing');
    }

    this.rows.set(item.id, row);
    this.syncRow(item.id);

    return row;
  };

  Archive.prototype.buildDetails = function (item) {
    var self = this;
    var durationLabel = humanDuration(item.duration);
    var sizeLabel = fileSize(item.size);
    var when = [dateNumeric(item.date), item.time, durationLabel].filter(Boolean).join(' · ');

    var playButton = el('button', {
      type: 'button',
      class: 'ba-button ba-button--primary',
      onclick: function () {
        self.startItem(item);
      }
    });
    playButton.setAttribute('data-details-play', '1');

    var actions = [playButton];

    if (this.downloadEnabled) {
      var downloadLabel = el('span', { class: 'ba-download__label', text: L.download || '' });
      var download = el('a', {
        class: 'ba-button ba-button--ghost ba-download',
        href: this.downloadHref(item),
        download: this.downloadName(item)
      }, [
        icon('download'),
        downloadLabel,
        sizeLabel ? el('span', { class: 'ba-download__size', text: sizeLabel }) : null
      ]);
      actions.push(download);
    }

    var body = [
      el('p', { class: 'ba-details__when', text: when }),
      el('div', { class: 'ba-details__actions' }, actions)
    ];

    if (item.description) {
      body.push(el('p', { class: 'ba-details__description', text: item.description }));
    }

    if (this.showPlaylist && Array.isArray(item.playlist) && item.playlist.length) {
      var tracks = item.playlist.map(function (track) {
        return el('li', { class: 'ba-track' }, [
          el('span', { class: 'ba-track__time', text: track.time }),
          el('span', { class: 'ba-track__song' }, [
            el('strong', { class: 'ba-track__artist', text: track.artist }),
            el('span', { class: 'ba-track__title', text: track.title })
          ])
        ]);
      });

      body.push(el('div', { class: 'ba-details__playlist' }, [
        el('h4', { class: 'ba-details__heading', text: L.playlist || '' }),
        el('ol', { class: 'ba-tracklist' }, tracks)
      ]));
    }

    var inner = el('div', { class: 'ba-episode__details-inner' }, body);
    this.syncDetailsPlay(inner, item);

    return inner;
  };

  Archive.prototype.toggleDetails = function (item, row, button, details) {
    var open = this.open.has(item.id);

    if (open) {
      this.open.delete(item.id);
      details.hidden = true;
      button.setAttribute('aria-expanded', 'false');
      button.setAttribute('aria-label', L.showDetails || '');
      return;
    }

    if (!details.firstChild) {
      details.appendChild(this.buildDetails(item));
    }

    this.open.add(item.id);
    details.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    button.setAttribute('aria-label', L.hideDetails || '');
  };

  Archive.prototype.renderResume = function () {
    var self = this;
    var item = this.items.find(function (candidate) {
      return candidate.id === self.lastId;
    });
    var progress = item ? this.progress[item.id] : null;

    this.resumeSlot.replaceChildren();

    if (!item || !progress || progress.p < 30 || (item.duration > 0 && progress.p > item.duration - 30)) {
      return;
    }

    if (this.playingId === item.id) {
      return;
    }

    var button = el('button', { type: 'button', class: 'ba-resume' }, [
      el('span', { class: 'ba-resume__icon' }, icon('play')),
      el('span', { class: 'ba-resume__text' }, [
        el('span', { class: 'ba-resume__label', text: L.continueListening || '' }),
        el('span', { class: 'ba-resume__title', text: item.show || item.title })
      ]),
      el('span', { class: 'ba-resume__time', text: timecode(progress.p) })
    ]);

    button.setAttribute('aria-label', format(L.continueAt || '', timecode(progress.p)) + ' — ' + (item.show || item.title));
    button.addEventListener('click', function () {
      self.startItem(item, progress.p);
    });

    this.resumeSlot.appendChild(button);
  };

  Archive.prototype.renderError = function () {
    var self = this;

    if (this.skeleton && this.skeleton.parentNode) {
      this.skeleton.parentNode.removeChild(this.skeleton);
    }

    this.toolbar.hidden = true;
    this.daysEl.hidden = true;
    this.metaEl.hidden = true;
    this.moreEl.hidden = true;
    this.resumeSlot.replaceChildren();
    this.announce(L.loadErrorTitle || '');

    this.listEl.replaceChildren(el('div', { class: 'ba-message', role: 'alert' }, [
      el('h2', { class: 'ba-message__title', text: L.loadErrorTitle || '' }),
      el('p', { class: 'ba-message__body', text: this.error || L.loadErrorBody || '' }),
      el('button', {
        type: 'button',
        class: 'ba-button ba-button--primary',
        text: L.retry || '',
        onclick: function () {
          self.load(true);
        }
      })
    ]));
  };

  Archive.prototype.showSkeleton = function () {
    this.listEl.replaceChildren();

    if (this.skeletonClone) {
      this.listEl.appendChild(this.skeletonClone.cloneNode(true));
    }
  };

  Archive.prototype.clearFilters = function () {
    this.query = '';
    this.program = '';
    this.day = '';
    this.visible = this.perPage;
    this.open.clear();

    if (this.searchInput) {
      this.searchInput.value = '';
    }

    if (this.searchClear) {
      this.searchClear.hidden = true;
    }

    if (this.programSelect) {
      this.programSelect.value = '';
    }

    this.renderDays();
    this.renderList();

    if (this.searchInput) {
      this.searchInput.focus();
    }
  };

  Archive.prototype.announce = function (message) {
    if (!this.statusEl) {
      return;
    }

    this.statusEl.textContent = '';

    window.setTimeout(function () {
      this.statusEl.textContent = message;
    }.bind(this), 30);
  };

  /* ----------------------------- playback ----------------------------- */

  Archive.prototype.ensureAudio = function () {
    if (this.audio) {
      return this.audio;
    }

    var self = this;
    var audio = document.createElement('audio');

    audio.preload = 'none';
    audio.setAttribute('playsinline', '');
    audio.className = 'ba-audio';
    document.body.appendChild(audio);

    audio.addEventListener('loadedmetadata', function () {
      if (isFinite(audio.duration) && audio.duration > 0) {
        self.duration = audio.duration;
      }

      if (self.pendingSeek > 0 && self.pendingSeek < (audio.duration || Infinity)) {
        try {
          audio.currentTime = self.pendingSeek;
        } catch (error) {
          /* seek not possible yet; ignore */
        }
      }

      self.pendingSeek = 0;
      self.syncTransport();
    });

    audio.addEventListener('play', function () {
      self.playing = true;
      self.ended = false;
      self.audioError = false;
      self.syncTransport();
      self.syncPlayState();
    });

    audio.addEventListener('pause', function () {
      self.playing = false;
      self.syncPlayState();
      self.rememberProgress();
    });

    audio.addEventListener('timeupdate', function () {
      var now = Date.now();

      if (now - self.lastUi > 250) {
        self.lastUi = now;
        self.syncTransportProgress();
      }

      if (now - self.lastStore > 5000) {
        self.lastStore = now;
        self.rememberProgress();
      }

      if (now - self.lastMedia > 1000) {
        self.lastMedia = now;
        self.updatePositionState();
      }
    });

    audio.addEventListener('ended', function () {
      self.playing = false;
      self.ended = true;
      self.syncPlayState();
      self.rememberProgress();
      self.announce(L.ended || '');
    });

    audio.addEventListener('error', function () {
      self.audioError = true;
      self.playing = false;
      self.syncPlayState();
      self.announce(L.playbackError || '');
    });

    this.audio = audio;

    return audio;
  };

  Archive.prototype.currentItem = function () {
    var self = this;

    return this.items.find(function (item) {
      return item.id === self.playingId;
    }) || null;
  };

  Archive.prototype.startItem = function (item, startAt) {
    var self = this;

    if (this.playingId === item.id && this.audio) {
      this.audioError = false;

      if (!this.audio.paused) {
        this.audio.pause();
        return;
      }

      if (this.ended) {
        this.audio.currentTime = 0;
        this.ended = false;
      }

      this.audio.play().catch(function (error) {
        if (error && error.name !== 'AbortError') {
          self.audioError = true;
          self.syncPlayState();
        }
      });
      return;
    }

    if (this.playingId && this.playingId !== item.id && this.audio) {
      this.rememberProgress();
    }

    document.dispatchEvent(new CustomEvent('ba:play', { detail: { owner: this.uid } }));

    var audio = this.ensureAudio();
    var stored = typeof startAt === 'number' ? startAt : this.savedPosition(item);

    this.playingId = item.id;
    this.ended = false;
    this.audioError = false;
    this.duration = item.duration || 0;
    this.pendingSeek = stored > 0 ? stored : 0;

    if (this.audioSrc !== item.audio) {
      this.audioSrc = item.audio;
      audio.src = item.audio;
    } else if (stored > 0) {
      try {
        audio.currentTime = stored;
      } catch (error) {
        /* ignore */
      }
    }

    this.syncTransport();
    this.openTransport();
    this.syncRows();
    this.renderResume();
    this.setLast(item.id);

    audio.play().catch(function (error) {
      if (error && error.name === 'AbortError') {
        return;
      }

      self.audioError = true;
      self.syncPlayState();
      self.announce(L.playbackError || '');
    });

    this.announce(format(L.nowPlaying || '%s', item.show || item.title));
    this.updateMediaSession(item);
  };

  Archive.prototype.savedPosition = function (item) {
    var entry = this.progress[item.id];

    if (!entry || entry.p < 10) {
      return 0;
    }

    if (item.duration > 0 && entry.p > item.duration - 15) {
      return 0;
    }

    return entry.p;
  };

  Archive.prototype.togglePlayback = function () {
    var audio = this.audio;

    if (!this.playingId || !audio) {
      return;
    }

    if (audio.paused) {
      this.audioError = false;

      if (this.ended) {
        audio.currentTime = 0;
        this.ended = false;
      }

      audio.play().catch(function () {
        /* handled by the error event */
      });
    } else {
      audio.pause();
    }
  };

  Archive.prototype.seek = function (seconds) {
    if (!this.audio || !isFinite(this.duration) || this.duration <= 0) {
      return;
    }

    var target = Math.max(0, Math.min(this.duration, Number(seconds) || 0));

    try {
      this.audio.currentTime = target;
      this.syncTransportProgress();
    } catch (error) {
      /* ignore */
    }
  };

  Archive.prototype.skip = function (delta) {
    if (!this.audio) {
      return;
    }

    this.seek((this.audio.currentTime || 0) + delta);
  };

  Archive.prototype.openTransport = function () {
    if (!this.transport) {
      return;
    }

    window.clearTimeout(this.closeTimer);

    // Keep the transport above site widgets that are injected later with the
    // maximum z-index; equal z-index means the later element paints on top.
    if (this.transport.parentNode !== document.body || document.body.lastElementChild !== this.transport) {
      document.body.appendChild(this.transport);
    }

    if (this.transport.hidden) {
      this.transport.hidden = false;
    }

    this.focusItemId = this.playingId || '';
    var self = this;

    window.requestAnimationFrame(function () {
      self.transport.classList.add('is-open');
      document.body.classList.add('ba-transport-open');
      self.updateTransportSpace();
    });
  };

  Archive.prototype.closeTransport = function () {
    var self = this;

    if (!this.transport) {
      return;
    }

    var focusWasInside = this.transport.contains(document.activeElement);
    var restoreId = this.focusItemId;

    if (this.audio) {
      this.audio.pause();
    }

    this.rememberProgress();
    this.playingId = '';
    this.focusItemId = '';
    this.playing = false;
    this.ended = false;
    this.transport.classList.remove('is-open');
    this.updateBodyClass();
    this.syncRows();
    this.renderResume();

    this.closeTimer = window.setTimeout(function () {
      if (self.transport && !self.transport.classList.contains('is-open')) {
        self.transport.hidden = true;
      }
    }, 300);

    if (focusWasInside || document.activeElement === document.body) {
      var restoreRow = restoreId ? this.rows.get(restoreId) : null;
      var restoreButton = restoreRow ? restoreRow.querySelector('.ba-episode__play') : null;

      if (!restoreButton || !document.contains(restoreButton)) {
        restoreButton = this.listEl.querySelector('.ba-episode__play');
      }

      if (restoreButton) {
        restoreButton.focus({ preventScroll: true });
      }
    }
  };

  Archive.prototype.updateBodyClass = function () {
    var anyOpen = document.querySelector('.ba-transport.is-open');

    document.body.classList.toggle('ba-transport-open', !!anyOpen);

    if (!anyOpen) {
      document.documentElement.style.removeProperty('--ba-transport-height');
    }
  };

  Archive.prototype.updateTransportSpace = function () {
    if (!this.transport || this.transport.hidden) {
      return;
    }

    var height = Math.ceil(this.transport.getBoundingClientRect().height);

    if (height > 0) {
      document.documentElement.style.setProperty('--ba-transport-height', height + 'px');
    }
  };

  Archive.prototype.syncPlayState = function () {
    this.syncRows();
    this.syncTransportControls();
    this.renderResume();

    if ('mediaSession' in navigator) {
      try {
        navigator.mediaSession.playbackState = this.playing ? 'playing' : 'paused';
      } catch (error) {
        /* ignore */
      }
    }
  };

  Archive.prototype.syncRows = function () {
    var self = this;

    this.rows.forEach(function (row, id) {
      self.syncRow(id);
    });
  };

  Archive.prototype.syncRow = function (id) {
    var row = this.rows.get(id);
    var item = this.items.find(function (candidate) {
      return candidate.id === id;
    });

    if (!row || !item) {
      return;
    }

    var isCurrent = this.playingId === id;
    var isPlaying = isCurrent && this.playing;

    row.classList.toggle('is-playing', isCurrent);

    var button = row.querySelector('.ba-episode__play');

    if (button) {
      button.replaceChildren(icon(isPlaying ? 'pause' : 'play'));
      button.setAttribute('aria-label', format(isPlaying ? (L.pauseItem || '') : (L.playItem || ''), itemLabel(item)));
    }

    var details = row.querySelector('.ba-episode__details');

    if (details && details.firstChild) {
      this.syncDetailsPlay(details, item);
    }
  };

  Archive.prototype.syncDetailsPlay = function (container, item) {
    var button = container.querySelector('[data-details-play]');

    if (!button) {
      return;
    }

    var isCurrent = this.playingId === item.id;
    var isPlaying = isCurrent && this.playing;
    var label = isPlaying ? (L.pause || '') : (this.ended && isCurrent ? (L.replay || '') : (L.play || ''));
    var iconName = isPlaying ? 'pause' : (this.ended && isCurrent ? 'replay' : 'play');

    button.replaceChildren(icon(iconName), el('span', { text: label }));
    button.setAttribute('aria-label', format(isPlaying ? (L.pauseItem || '') : (L.playItem || ''), itemLabel(item)));
  };

  Archive.prototype.syncTransport = function () {
    var item = this.currentItem();

    if (!item) {
      return;
    }

    this.trackTitle.textContent = item.show || item.title;

    var meta = [];
    var durationLabel = humanDuration(item.duration);

    if (item.presenter) {
      meta.push(format(L.presentedBy || '%s', item.presenter));
    }

    meta.push(dateShort(item.date));

    if (item.time) {
      meta.push(item.time);
    }

    if (durationLabel) {
      meta.push(durationLabel);
    }

    this.trackMeta.textContent = meta.filter(Boolean).join(' · ');

    var covers = [item.image, item.fallback, PLACEHOLDER].filter(Boolean);
    var coverIndex = 0;

    this.cover.style.visibility = '';
    this.cover.onerror = function () {
      coverIndex += 1;

      if (coverIndex < covers.length) {
        this.src = covers[coverIndex];
      } else {
        this.onerror = null;
        this.style.visibility = 'hidden';
      }
    };
    this.cover.src = covers[0] || PLACEHOLDER;

    this.transportDownload.href = this.downloadHref(item);
    this.transportDownload.setAttribute('download', this.downloadName(item));
    this.transportDownload.hidden = !this.downloadEnabled;

    this.syncTransportControls();
    this.syncTransportProgress();
  };

  Archive.prototype.syncTransportControls = function () {
    var playing = !!this.audio && !this.audio.paused;
    var error = this.audioError;

    this.transportPlay.replaceChildren(icon(error ? 'replay' : (playing ? 'pause' : 'play')));
    this.transportPlay.setAttribute('aria-label', error ? (L.replay || '') : (playing ? (L.pause || '') : (this.ended ? (L.replay || '') : (L.play || ''))));
    this.transportPlay.disabled = !this.playingId;

    if (error) {
      this.trackMeta.textContent = L.playbackError || '';
    }

    if ('mediaSession' in navigator) {
      try {
        navigator.mediaSession.playbackState = playing ? 'playing' : 'paused';
      } catch (ignore) {
        /* ignore */
      }
    }
  };

  Archive.prototype.syncTransportProgress = function () {
    if (!this.audio) {
      return;
    }

    var duration = isFinite(this.duration) && this.duration > 0 ? this.duration : 0;
    var current = Math.min(this.audio.currentTime || 0, duration || 0);

    this.currentTimeEl.textContent = timecode(current);
    this.totalTimeEl.textContent = timecode(duration);

    if (duration > 0) {
      this.transportSeek.disabled = false;
      this.transportSeek.max = String(Math.round(duration));
      this.transportSeek.value = String(Math.round(current));
      this.transportSeek.setAttribute('aria-valuetext', timecode(current) + ' / ' + timecode(duration));
      this.transport.style.setProperty('--ba-progress', ((current / duration) * 100).toFixed(2) + '%');
    } else {
      this.transportSeek.disabled = true;
      this.transport.style.setProperty('--ba-progress', '0%');
    }
  };

  Archive.prototype.rememberProgress = function () {
    if (!this.playingId || !this.audio) {
      return;
    }

    var duration = isFinite(this.duration) && this.duration > 0 ? this.duration : (this.audio.duration || 0);
    var current = this.audio.currentTime || 0;

    if (current < 5) {
      return;
    }

    this.progress[this.playingId] = {
      p: Math.round(current),
      d: Math.round(duration),
      t: Date.now()
    };

    Store.save(this.progress);
  };

  Archive.prototype.setLast = function (id) {
    this.lastId = id;
    Store.setLast(id);
  };

  Archive.prototype.downloadHref = function (item) {
    var base = cfg.restUrl.replace(/\/?$/, '/') + 'download';

    return base + '?url=' + encodeURIComponent(item.audio) + '&name=' + encodeURIComponent(this.downloadName(item));
  };

  Archive.prototype.downloadName = function (item) {
    var name = [this.station, item.show || item.title, item.date, String(item.time || '').replace(/\s*[–-]\s*/, '-')]
      .filter(Boolean)
      .join(' - ');

    name = sanitizeFilename(name) || 'broadcast';

    return name + '.' + extension(item.audio);
  };

  Archive.prototype.updateMediaSession = function (item) {
    if (!('mediaSession' in navigator) || typeof window.MediaMetadata === 'undefined') {
      return;
    }

    var self = this;
    var artwork = item.image ? [{ src: item.image, sizes: '512x512', type: mimeFor(item.image) }] : [];

    try {
      navigator.mediaSession.metadata = new window.MediaMetadata({
        title: item.show || item.title,
        artist: [item.presenter, this.station].filter(Boolean).join(' · '),
        album: dateLong(item.date),
        artwork: artwork
      });

      navigator.mediaSession.setActionHandler('play', function () {
        self.audio.play();
      });
      navigator.mediaSession.setActionHandler('pause', function () {
        self.audio.pause();
      });
      navigator.mediaSession.setActionHandler('seekbackward', function (details) {
        self.skip(-(details.seekOffset || 15));
      });
      navigator.mediaSession.setActionHandler('seekforward', function (details) {
        self.skip(details.seekOffset || 15);
      });
      navigator.mediaSession.setActionHandler('seekto', function (details) {
        if (typeof details.seekTime === 'number') {
          self.seek(details.seekTime);
        }
      });
      navigator.mediaSession.setActionHandler('stop', function () {
        self.closeTransport();
      });
    } catch (error) {
      /* Media Session is best effort. */
    }
  };

  Archive.prototype.updatePositionState = function () {
    if (!('mediaSession' in navigator) || typeof navigator.mediaSession.setPositionState !== 'function') {
      return;
    }

    var duration = this.audio && isFinite(this.audio.duration) ? this.audio.duration : 0;

    if (!duration || duration <= 0) {
      return;
    }

    try {
      navigator.mediaSession.setPositionState({
        duration: duration,
        playbackRate: this.audio.playbackRate || 1,
        position: Math.min(duration, this.audio.currentTime || 0)
      });
    } catch (error) {
      /* ignore */
    }
  };

  Archive.prototype.bindGlobal = function () {
    var self = this;

    document.addEventListener('ba:play', function (event) {
      if (!event.detail || event.detail.owner === self.uid) {
        return;
      }

      if (self.audio && !self.audio.paused) {
        self.audio.pause();
      }
    });

    window.addEventListener('pagehide', function () {
      self.rememberProgress();
    });

    document.addEventListener('visibilitychange', function () {
      if (document.visibilityState === 'hidden') {
        self.rememberProgress();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.defaultPrevented || event.key !== 'Escape' || !self.transport || self.transport.hidden) {
        return;
      }

      var tag = (event.target.tagName || '').toLowerCase();

      if (tag === 'input' || tag === 'select' || tag === 'textarea') {
        return;
      }

      event.preventDefault();
      self.closeTransport();
    });
  };

  /* ---------------------------------------------------------------------
   * Boot.
   * ------------------------------------------------------------------ */

  function boot() {
    var roots = document.querySelectorAll('[data-ba-root]');

    Array.prototype.forEach.call(roots, function (root) {
      if (root.getAttribute('data-ba-ready') === '1') {
        return;
      }

      root.setAttribute('data-ba-ready', '1');

      try {
        new Archive(root).init();
      } catch (error) {
        if (window.console && window.console.error) {
          window.console.error('Broadcast Archive:', error);
        }
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
