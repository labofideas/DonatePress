(function () {
  var tabs = document.querySelectorAll('.donatepress-tabs .tab');
  var panels = document.querySelectorAll('.donatepress-panel');

  if (tabs.length && panels.length) {
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var target = tab.getAttribute('data-tab');

        tabs.forEach(function (t) {
          t.classList.remove('is-active');
          t.setAttribute('aria-selected', 'false');
        });

        panels.forEach(function (panel) {
          var match = panel.getAttribute('data-panel') === target;
          panel.classList.toggle('is-active', match);
          panel.hidden = !match;
        });

        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');
      });
    });
  }

  document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-copy-target]');
    if (!button) {
      return;
    }

    var targetId = button.getAttribute('data-copy-target');
    var input = targetId ? document.getElementById(targetId) : null;
    if (!input) {
      return;
    }

    input.focus();
    input.select();
    input.setSelectionRange(0, input.value.length);

    var done = function () {
      window.donatepress.toast('Copied to clipboard', 'success');
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done);
      return;
    }

    if (document.execCommand('copy')) {
      done();
    }
  });

  document.addEventListener('click', function (event) {
    var link = event.target.closest('.button-link-delete');
    if (!link) {
      return;
    }
    if (!window.confirm('Are you sure you want to delete this item?')) {
      event.preventDefault();
    }
  });

  var container = null;

  function getContainer() {
    if (container) {
      return container;
    }
    container = document.createElement('div');
    container.className = 'dp-toast-container';
    document.body.appendChild(container);
    return container;
  }

  function toast(message, type) {
    var el = document.createElement('div');
    el.className = 'dp-toast' + (type ? ' is-' + type : '');
    el.textContent = message;
    getContainer().appendChild(el);

    window.setTimeout(function () {
      el.classList.add('is-leaving');
      window.setTimeout(function () {
        el.remove();
      }, 220);
    }, 3000);
  }

  window.donatepress = window.donatepress || {};
  window.donatepress.toast = toast;
})();
