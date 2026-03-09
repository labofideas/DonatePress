(function () {
  var tabs = document.querySelectorAll('.donatepress-tabs .tab');
  var panels = document.querySelectorAll('.donatepress-panel');

  if (!tabs.length || !panels.length) {
    return;
  }

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
      var original = button.textContent;
      button.textContent = 'Copied';
      window.setTimeout(function () {
        button.textContent = original;
      }, 1200);
    };

    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(input.value).then(done);
      return;
    }

    if (document.execCommand('copy')) {
      done();
    }
  });
})();
