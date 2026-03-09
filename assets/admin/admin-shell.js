(function () {
  var roots = document.querySelectorAll('.wrap.donatepress-admin');
  if (!roots.length) return;

  var links = [
    { label: 'Setup', slug: 'donatepress-setup' },
    { label: 'Settings', slug: 'donatepress' },
    { label: 'Donations', slug: 'donatepress-donations' },
    { label: 'Donors', slug: 'donatepress-donors' },
    { label: 'Forms', slug: 'donatepress-forms' },
    { label: 'Campaigns', slug: 'donatepress-campaigns' },
    { label: 'Reports', slug: 'donatepress-reports' },
    { label: 'Subscriptions', slug: 'donatepress-subscriptions' }
  ];

  var params = new URLSearchParams(window.location.search);
  var current = params.get('page') || 'donatepress';

  Array.prototype.forEach.call(roots, function (root) {
    if (root.querySelector('.dp-admin-shell')) return;

    var shell = document.createElement('div');
    shell.className = 'dp-admin-shell';

    var header = document.createElement('div');
    header.className = 'dp-admin-shell-head';
    header.innerHTML =
      '<h1>DonatePress Admin</h1>' +
      '<p>v1 operations shell for setup, donors, campaigns, forms, and reporting.</p>';
    shell.appendChild(header);

    var nav = document.createElement('nav');
    nav.className = 'dp-admin-shell-nav';
    links.forEach(function (item) {
      var a = document.createElement('a');
      a.href = 'admin.php?page=' + item.slug;
      a.textContent = item.label;
      if (item.slug === current) a.classList.add('is-active');
      nav.appendChild(a);
    });
    shell.appendChild(nav);

    root.insertBefore(shell, root.firstChild);
  });
})();
