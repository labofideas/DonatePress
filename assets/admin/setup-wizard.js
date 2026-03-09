(function (window) {
  var wp = window.wp || {};
  var el = wp.element || {};
  var createElement = el.createElement;
  var Fragment = el.Fragment;
  var useMemo = el.useMemo;
  var useState = el.useState;
  var useEffect = el.useEffect;

  if (!createElement || !window.donatepressSetupWizard) {
    return;
  }

  function pct(done, total) {
    if (!total) return 0;
    return Math.max(0, Math.min(100, Math.round((done / total) * 100)));
  }

  function callJson(url, method, payload, nonce) {
    return fetch(url, {
      method: method,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce
      },
      body: payload ? JSON.stringify(payload) : undefined
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) {
          throw new Error((data && data.message) || 'Request failed');
        }
        return data;
      });
    });
  }

  function SetupWizardApp(props) {
    var data = props.data || {};
    var statusState = useState(data.status || {});
    var status = statusState[0];
    var setStatus = statusState[1];
    var progress = useMemo(function () {
      var steps = 3;
      var complete = 0;
      if ((status.required_set || 0) >= (status.required_total || 1)) complete += 1;
      if (status.stripe_configured || status.paypal_configured) complete += 1;
      if ((status.forms_count || 0) > 0) complete += 1;
      return {
        complete: complete,
        total: steps,
        percent: pct(complete, steps)
      };
    }, [status]);
    var creating = useState(false);
    var busy = creating[0];
    var setBusy = creating[1];
    var messageState = useState('');
    var message = messageState[0];
    var setMessage = messageState[1];
    var errorState = useState('');
    var error = errorState[0];
    var setError = errorState[1];
    var saveState = useState({ organization_name: '', organization_email: '' });
    var quickSave = saveState[0];
    var setQuickSave = saveState[1];

    function reloadStatus() {
      return callJson(data.restBase + '/setup-wizard/status', 'GET', null, data.nonce)
        .then(function (res) {
          if (res && res.status) {
            setStatus(res.status);
          }
        });
    }

    useEffect(function () {
      reloadStatus();
    }, []);

    function saveOrganization() {
      if (!quickSave.organization_name || !quickSave.organization_email) {
        setError('Organization name and email are required.');
        return;
      }
      setBusy(true);
      setError('');
      setMessage('');

      callJson(
        data.restBase + '/setup-wizard/save',
        'POST',
        {
          organization_name: quickSave.organization_name,
          organization_email: quickSave.organization_email,
          receipt_legal_entity_name: quickSave.organization_name
        },
        data.nonce
      )
        .then(function (res) {
          if (res && res.status) {
            setStatus(res.status);
          }
          setMessage('Organization settings saved.');
        })
        .catch(function (err) {
          setError(err.message || 'Could not save organization settings.');
        })
        .finally(function () {
          setBusy(false);
        });
    }

    function createFirstForm() {
      setBusy(true);
      setError('');
      setMessage('');

      callJson(data.restBase + '/setup-wizard/first-form', 'POST', {}, data.nonce)
        .then(function (res) {
          if (res && res.status) {
            setStatus(res.status);
          }
          setMessage('First form created. Opening Forms page.');
          window.location.href = data.formsUrl;
        })
        .catch(function (err) {
          setError(err.message || 'Could not create form.');
        })
        .finally(function () {
          setBusy(false);
        });
    }

    function completeSetup() {
      setBusy(true);
      setError('');
      setMessage('');
      callJson(data.restBase + '/setup-wizard/complete', 'POST', {}, data.nonce)
        .then(function (res) {
          if (res && res.status) {
            setStatus(res.status);
          }
          setMessage('Setup marked complete.');
        })
        .catch(function (err) {
          setError(err.message || 'Setup is not complete yet.');
        })
        .finally(function () {
          setBusy(false);
        });
    }

    return createElement(
      Fragment,
      null,
      createElement('div', { className: 'dp-wizard-progress' },
        createElement('p', { className: 'dp-wizard-progress-label' }, 'Setup Progress'),
        createElement('div', { className: 'dp-wizard-progress-bar' },
          createElement('span', { style: { width: progress.percent + '%' } })
        ),
        createElement('p', null, progress.complete + '/' + progress.total + ' steps complete')
      ),
      createElement('ol', { className: 'dp-wizard-steps' },
        createElement('li', { className: (status.required_set >= status.required_total) ? 'is-complete' : '' },
          createElement('h3', null, '1) Organization & Compliance'),
          createElement('p', null, status.required_set + '/' + status.required_total + ' required fields complete.'),
          createElement('div', { className: 'dp-wizard-inline-form' },
            createElement('input', {
              type: 'text',
              placeholder: 'Organization Name',
              value: quickSave.organization_name,
              onChange: function (event) {
                setQuickSave({
                  organization_name: event.target.value,
                  organization_email: quickSave.organization_email
                });
              }
            }),
            createElement('input', {
              type: 'email',
              placeholder: 'Organization Email',
              value: quickSave.organization_email,
              onChange: function (event) {
                setQuickSave({
                  organization_name: quickSave.organization_name,
                  organization_email: event.target.value
                });
              }
            }),
            createElement('button', { className: 'button button-secondary', disabled: busy, onClick: saveOrganization }, 'Quick Save')
          ),
          createElement('a', { className: 'button button-secondary', href: data.settingsUrl }, 'Open Settings')
        ),
        createElement('li', { className: (status.stripe_configured || status.paypal_configured) ? 'is-complete' : '' },
          createElement('h3', null, '2) Payment Gateway'),
          createElement('p', null, (status.stripe_configured ? 'Stripe configured. ' : '') + (status.paypal_configured ? 'PayPal configured.' : 'No gateway configured yet.')),
          createElement('a', { className: 'button button-secondary', href: data.settingsUrl }, 'Configure Gateway')
        ),
        createElement('li', { className: (status.forms_count > 0) ? 'is-complete' : '' },
          createElement('h3', null, '3) First Donation Form'),
          createElement('p', null, (status.forms_count || 0) + ' form(s) available.'),
          (status.forms_count > 0)
            ? createElement('a', { className: 'button button-secondary', href: data.formsUrl }, 'Manage Forms')
            : createElement('button', { className: 'button button-primary', disabled: busy, onClick: createFirstForm }, busy ? 'Creating...' : 'Create First Form')
        )
      ),
      createElement('div', { className: 'dp-wizard-actions' },
        createElement('button', { className: 'button button-secondary', disabled: busy, onClick: reloadStatus }, 'Refresh Status'),
        createElement('a', { className: 'button button-secondary', href: data.demoImportUrl }, 'Import Demo Content'),
        createElement('button', { className: 'button button-primary', disabled: busy || !(progress.complete >= progress.total), onClick: completeSetup }, 'Mark Setup Complete')
      ),
      !!message && createElement('p', { className: 'dp-wizard-message is-success' }, message),
      !!error && createElement('p', { className: 'dp-wizard-message is-error' }, error)
    );
  }

  var root = document.getElementById('donatepress-setup-wizard-root');
  if (!root) {
    return;
  }

  var render = el.render || (window.ReactDOM && window.ReactDOM.render);
  if (!render) {
    return;
  }

  render(createElement(SetupWizardApp, { data: window.donatepressSetupWizard }), root);
})(window);
