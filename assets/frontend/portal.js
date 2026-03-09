(function () {
  var hasBooted = false;
  function escapeHtml(value) {
    return String(value || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function showMessage(root, message, isError) {
    var node = root.querySelector('[data-dp-message]');
    if (!node) return;
    node.textContent = message || '';
    node.classList.toggle('is-error', !!isError);
    node.classList.toggle('is-success', !isError && !!message);
  }

  function readTokenFromUrl() {
    var params = new URLSearchParams(window.location.search);
    return params.get('dp_token') || '';
  }

  function clearTokenFromUrl() {
    var url = new URL(window.location.href);
    url.searchParams.delete('dp_token');
    window.history.replaceState({}, document.title, url.toString());
  }

  function getSessionToken() {
    return localStorage.getItem('donatepress_portal_session') || '';
  }

  function setSessionToken(token) {
    if (!token) return;
    localStorage.setItem('donatepress_portal_session', token);
  }

  function clearSessionToken() {
    localStorage.removeItem('donatepress_portal_session');
  }

  function renderProfile(node, profile) {
    if (!node) return;
    var firstName = escapeHtml(profile.first_name || '');
    var lastName = escapeHtml(profile.last_name || '');
    var phone = escapeHtml(profile.phone || '');
    node.innerHTML =
      '<p><strong>Email:</strong> ' + escapeHtml(profile.email || '') + '</p>' +
      '<p><strong>Donations:</strong> ' + escapeHtml(profile.donation_count || 0) + '</p>' +
      '<p><strong>Completed Volume:</strong> ' + escapeHtml(profile.completed_volume || 0) + '</p>' +
      '<form data-dp-profile-form class="dp-profile-form">' +
      '<label>First name</label><input type="text" name="first_name" value="' + firstName + '" />' +
      '<label>Last name</label><input type="text" name="last_name" value="' + lastName + '" />' +
      '<label>Phone</label><input type="text" name="phone" value="' + phone + '" />' +
      '<button type="submit">Update Profile</button>' +
      '</form>';
  }

  function renderDonations(node, items) {
    if (!node) return;
    if (!items || !items.length) {
      node.innerHTML = '<p>No donations found.</p>';
      return;
    }

    var rows = items
      .map(function (item) {
        return (
          '<tr>' +
          '<td>' + (item.donation_number || '') + '</td>' +
          '<td>' + (item.currency || '') + ' ' + (item.amount || '') + '</td>' +
          '<td>' + (item.status || '') + '</td>' +
          '<td>' + (item.donated_at || '') + '</td>' +
          '<td><button type="button" data-dp-receipt-id="' + (item.id || 0) + '">Receipt</button></td>' +
          '</tr>'
        );
      })
      .join('');

    node.innerHTML =
      '<table><thead><tr><th>Donation #</th><th>Amount</th><th>Status</th><th>Date</th><th>Receipt</th></tr></thead><tbody>' +
      rows +
      '</tbody></table>';
  }

  function actionButtons(item) {
    var status = (item.status || '').toLowerCase();
    var buttons = [];
    if (status === 'active') {
      buttons.push('<button type="button" data-dp-sub-action="pause">Pause</button>');
    }
    if (status === 'paused' || status === 'failed') {
      buttons.push('<button type="button" data-dp-sub-action="resume">Resume</button>');
    }
    if (status === 'active' || status === 'paused' || status === 'failed' || status === 'pending') {
      buttons.push('<button type="button" data-dp-sub-action="cancel">Cancel</button>');
    }
    return buttons.join(' ');
  }

  function renderSubscriptions(node, items) {
    if (!node) return;
    if (!items || !items.length) {
      node.innerHTML = '<h4>Subscriptions</h4><p>No subscriptions found.</p>';
      return;
    }

    var rows = items
      .map(function (item) {
        return (
          '<tr>' +
          '<td>' + (item.subscription_number || '') + '</td>' +
          '<td>' + (item.currency || '') + ' ' + (item.amount || '') + '</td>' +
          '<td>' + (item.frequency || '') + '</td>' +
          '<td>' + (item.status || '') + '</td>' +
          '<td>' +
          '<div class="dp-sub-actions" data-dp-sub-id="' + (item.id || 0) + '">' +
          actionButtons(item) +
          '</div>' +
          '</td>' +
          '</tr>'
        );
      })
      .join('');

    node.innerHTML =
      '<h4>Subscriptions</h4>' +
      '<table><thead><tr><th>Subscription #</th><th>Amount</th><th>Frequency</th><th>Status</th><th>Actions</th></tr></thead><tbody>' +
      rows +
      '</tbody></table>';
  }

  function renderAnnualSummary(node, items) {
    if (!node) return;
    if (!items || !items.length) {
      node.innerHTML = '<h4>Annual Summary</h4><p>No completed donations available yet.</p>';
      return;
    }

    var rows = items
      .map(function (item) {
        return (
          '<tr>' +
          '<td>' + (item.year || '') + '</td>' +
          '<td>' + (item.currency || '') + '</td>' +
          '<td>' + (item.donation_count || 0) + '</td>' +
          '<td>' + (item.total_amount || 0) + '</td>' +
          '</tr>'
        );
      })
      .join('');

    node.innerHTML =
      '<h4>Annual Summary</h4>' +
      '<p><button type="button" data-dp-annual-download>Download PDF</button> <button type="button" data-dp-annual-download-csv>Download CSV</button></p>' +
      '<table><thead><tr><th>Year</th><th>Currency</th><th>Donations</th><th>Total</th></tr></thead><tbody>' +
      rows +
      '</tbody></table>';
  }

  function switchStage(root, stage) {
    Array.prototype.forEach.call(root.querySelectorAll('[data-dp-stage]'), function (panel) {
      panel.hidden = panel.getAttribute('data-dp-stage') !== stage;
    });
  }

  async function callJson(url, method, payload, sessionToken, portalNonce) {
    var headers = { 'Content-Type': 'application/json' };
    if (sessionToken) {
      headers['X-DonatePress-Portal-Token'] = sessionToken;
    }
    if (portalNonce) {
      headers['X-DonatePress-Portal-Nonce'] = portalNonce;
    }
    var response = await fetch(url, {
      method: method,
      headers: headers,
      body: payload ? JSON.stringify(payload) : undefined
    });
    var data = await response.json();
    if (!response.ok) {
      throw new Error((data && data.message) || 'Request failed');
    }
    return data;
  }

  function downloadFromResponse(data) {
    var content = (data && data.content) || '';
    var filename = (data && data.filename) || 'download.txt';
    var mime = (data && data.mime) || 'text/plain';
    var encoding = (data && data.encoding) || '';
    var blob;

    if (encoding === 'base64') {
      var raw = atob(content);
      var bytes = new Uint8Array(raw.length);
      for (var i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
      }
      blob = new Blob([bytes], { type: mime });
    } else {
      blob = new Blob([content], { type: mime });
    }

    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
  }

  async function hydrateDashboard(root, base, sessionToken, portalNonce) {
    var me = await callJson(base + '/me', 'GET', null, sessionToken, portalNonce);
    var donations = await callJson(base + '/donations?limit=25', 'GET', null, sessionToken, portalNonce);
    var subscriptions = await callJson(base + '/subscriptions?limit=25', 'GET', null, sessionToken, portalNonce);
    var annualSummary = await callJson(base + '/annual-summary', 'GET', null, sessionToken, portalNonce);
    renderProfile(root.querySelector('[data-dp-profile]'), me.profile || {});
    renderDonations(root.querySelector('[data-dp-donations]'), donations.items || []);
    renderSubscriptions(root.querySelector('[data-dp-subscriptions]'), subscriptions.items || []);
    renderAnnualSummary(root.querySelector('[data-dp-annual-summary]'), annualSummary.items || []);
    switchStage(root, 'dashboard');
  }

  function authenticateWithToken(root, base, token, portalNonce) {
    if (!token) {
      return Promise.resolve();
    }

    showMessage(root, 'Signing you in...', false);

    return callJson(base + '/auth', 'POST', { token: token }, null, portalNonce)
      .then(function (data) {
        var sessionToken = data.session_token || '';
        setSessionToken(sessionToken);
        clearTokenFromUrl();
        showMessage(root, 'Login successful.', false);
        return hydrateDashboard(root, base, sessionToken, portalNonce);
      })
      .catch(function (error) {
        showMessage(root, error.message || 'Login failed.', true);
        switchStage(root, 'auth');
        throw error;
      });
  }

  function bootPortal() {
    if (hasBooted) {
      return;
    }

    var root = document.querySelector('[data-dp-portal]');
    if (!root) return;
    hasBooted = true;

    var base = root.getAttribute('data-rest-base');
    if (!base) return;
    var portalNonce = root.getAttribute('data-dp-nonce') || '';

    var requestForm = root.querySelector('[data-dp-request-link]');
    var authForm = root.querySelector('[data-dp-auth]');
    var logoutBtn = root.querySelector('[data-dp-logout]');

    var tokenFromUrl = readTokenFromUrl();
    if (tokenFromUrl && authForm) {
      authForm.querySelector('input[name="token"]').value = tokenFromUrl;
    }

    var existing = getSessionToken();
    if (existing) {
      hydrateDashboard(root, base, existing, portalNonce).catch(function () {
        clearSessionToken();
        if (tokenFromUrl) {
          authenticateWithToken(root, base, tokenFromUrl, portalNonce).catch(function () {});
        } else {
          switchStage(root, 'request');
        }
      });
    } else if (tokenFromUrl) {
      switchStage(root, 'auth');
      authenticateWithToken(root, base, tokenFromUrl, portalNonce).catch(function () {});
    } else {
      switchStage(root, 'request');
    }

    if (requestForm) {
      requestForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var email = requestForm.querySelector('input[name="email"]').value;
        callJson(base + '/request-link', 'POST', { email: email }, null, portalNonce)
          .then(function (data) {
            showMessage(root, data.message || 'If your email exists, a link has been sent.', false);
            switchStage(root, 'auth');
          })
          .catch(function (error) {
            showMessage(root, error.message || 'Could not send link.', true);
          });
      });
    }

    if (authForm) {
      authForm.addEventListener('submit', function (event) {
        event.preventDefault();
        var token = authForm.querySelector('input[name="token"]').value;
        callJson(base + '/auth', 'POST', { token: token }, null, portalNonce)
          .then(function (data) {
            setSessionToken(data.session_token || '');
            showMessage(root, 'Login successful.', false);
            return hydrateDashboard(root, base, data.session_token || '', portalNonce);
          })
          .catch(function (error) {
            showMessage(root, error.message || 'Login failed.', true);
          });
      });
    }

    if (logoutBtn) {
      logoutBtn.addEventListener('click', function () {
        var sessionToken = getSessionToken();
        callJson(base + '/logout', 'POST', {}, sessionToken, portalNonce).finally(function () {
          clearSessionToken();
          switchStage(root, 'request');
          showMessage(root, 'Logged out.', false);
        });
      });
    }

    root.addEventListener('click', function (event) {
      var receiptBtn = event.target.closest('[data-dp-receipt-id]');
      if (receiptBtn) {
        var receiptId = parseInt(receiptBtn.getAttribute('data-dp-receipt-id'), 10);
        var sessionToken = getSessionToken();
        if (!receiptId || !sessionToken) return;

        callJson(base + '/receipts/' + receiptId + '/download', 'GET', null, sessionToken, portalNonce)
          .then(function (data) {
            downloadFromResponse(data);
          })
          .catch(function (error) {
            showMessage(root, error.message || 'Could not download receipt.', true);
          });
        return;
      }

      var annualDownload = event.target.closest('[data-dp-annual-download]');
      if (annualDownload) {
        var sessionToken = getSessionToken();
        if (!sessionToken) return;

        callJson(base + '/annual-summary/download?format=pdf', 'GET', null, sessionToken, portalNonce)
          .then(function (data) {
            downloadFromResponse(data);
          })
          .catch(function (error) {
            showMessage(root, error.message || 'Could not download annual summary.', true);
          });
        return;
      }

      var annualDownloadCsv = event.target.closest('[data-dp-annual-download-csv]');
      if (annualDownloadCsv) {
        var sessionToken = getSessionToken();
        if (!sessionToken) return;

        callJson(base + '/annual-summary/download', 'GET', null, sessionToken, portalNonce)
          .then(function (data) {
            downloadFromResponse(data);
          })
          .catch(function (error) {
            showMessage(root, error.message || 'Could not download annual summary.', true);
          });
        return;
      }

      var btn = event.target.closest('[data-dp-sub-action]');
      if (!btn) return;

      var wrapper = btn.closest('[data-dp-sub-id]');
      if (!wrapper) return;

      var subscriptionId = parseInt(wrapper.getAttribute('data-dp-sub-id'), 10);
      if (!subscriptionId) return;

      var action = btn.getAttribute('data-dp-sub-action');
      var sessionToken = getSessionToken();
      if (!sessionToken) return;

      callJson(base + '/subscriptions/' + subscriptionId + '/action', 'POST', { action: action }, sessionToken, portalNonce)
        .then(function () {
          showMessage(root, 'Subscription updated.', false);
          return hydrateDashboard(root, base, sessionToken, portalNonce);
        })
        .catch(function (error) {
          showMessage(root, error.message || 'Could not update subscription.', true);
        });
    });

    root.addEventListener('submit', function (event) {
      var form = event.target.closest('[data-dp-profile-form]');
      if (!form) return;

      event.preventDefault();

      var sessionToken = getSessionToken();
      if (!sessionToken) return;

      var payload = {
        first_name: (form.querySelector('input[name="first_name"]') || {}).value || '',
        last_name: (form.querySelector('input[name="last_name"]') || {}).value || '',
        phone: (form.querySelector('input[name="phone"]') || {}).value || ''
      };

      callJson(base + '/profile', 'POST', payload, sessionToken, portalNonce)
        .then(function () {
          showMessage(root, 'Profile updated.', false);
          return hydrateDashboard(root, base, sessionToken, portalNonce);
        })
        .catch(function (error) {
          showMessage(root, error.message || 'Could not update profile.', true);
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootPortal);
  } else {
    bootPortal();
  }
})();
