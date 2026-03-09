(function () {
  var stripeJsPromise = null;

  function visible(el) {
    return !!el && !el.hidden;
  }

  function loadStripeJs() {
    if (window.Stripe) {
      return Promise.resolve(window.Stripe);
    }

    if (stripeJsPromise) {
      return stripeJsPromise;
    }

    stripeJsPromise = new Promise(function (resolve, reject) {
      var script = document.createElement('script');
      script.src = 'https://js.stripe.com/v3/';
      script.async = true;
      script.onload = function () {
        if (window.Stripe) {
          resolve(window.Stripe);
          return;
        }
        reject(new Error('Stripe.js failed to initialize.'));
      };
      script.onerror = function () {
        reject(new Error('Stripe.js could not be loaded.'));
      };
      document.head.appendChild(script);
    });

    return stripeJsPromise;
  }

  function validateStep(step) {
    if (!step) return true;
    var inputs = step.querySelectorAll('input,select,textarea');
    for (var i = 0; i < inputs.length; i++) {
      var el = inputs[i];
      if (el.disabled || !el.willValidate) continue;
      if (!el.checkValidity()) {
        el.reportValidity();
        return false;
      }
    }
    return true;
  }

  function setupMultiStep(form) {
    if (form.getAttribute('data-dp-multistep') !== '1') return;

    var steps = form.querySelectorAll('[data-dp-step]');
    if (!steps.length) return;

    var prev = form.querySelector('[data-dp-prev]');
    var next = form.querySelector('[data-dp-next]');
    var submit = form.querySelector('[data-dp-submit]');
    var stepField = form.querySelector('[data-dp-form-step]');
    var current = 1;

    function render() {
      Array.prototype.forEach.call(steps, function (step, index) {
        var active = index + 1 === current;
        step.hidden = !active;
      });
      if (prev) prev.hidden = current === 1;
      if (next) next.hidden = current >= steps.length;
      if (submit) submit.hidden = current < steps.length;
      if (stepField) stepField.value = String(current);
    }

    if (prev) {
      prev.addEventListener('click', function () {
        current = Math.max(1, current - 1);
        render();
      });
    }

    if (next) {
      next.addEventListener('click', function () {
        var currentStep = form.querySelector('[data-dp-step="' + current + '"]');
        if (!validateStep(currentStep)) return;
        current = Math.min(steps.length, current + 1);
        render();
      });
    }

    render();
  }

  function setupAmountPresets(form) {
    var amountInput = form.querySelector('[data-dp-amount-input]');
    if (!amountInput) return;
    var presetsWrap = form.querySelector('[data-dp-amount-presets]');
    if (!presetsWrap) return;

    presetsWrap.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-dp-amount]');
      if (!btn) return;
      event.preventDefault();
      amountInput.value = btn.getAttribute('data-dp-amount') || amountInput.value;

      Array.prototype.forEach.call(presetsWrap.querySelectorAll('.dp-preset'), function (item) {
        item.classList.remove('is-active');
      });
      btn.classList.add('is-active');
    });
  }

  function serialize(form) {
    var out = {};
    Array.prototype.forEach.call(form.elements, function (el) {
      if (!el.name || el.disabled) return;
      if (el.type === 'checkbox') {
        out[el.name] = !!el.checked;
        return;
      }
      if (el.type === 'radio') {
        if (el.checked) out[el.name] = el.value;
        return;
      }
      out[el.name] = el.value;
    });
    return out;
  }

  function setMessage(form, message, isError) {
    var node = form.querySelector('[data-dp-message]');
    if (!node) return;
    node.textContent = message;
    node.classList.toggle('is-error', !!isError);
    node.classList.toggle('is-success', !isError);
  }

  function paymentPanel(form) {
    return form.querySelector('[data-dp-payment-panel]');
  }

  function hidePaymentPanel(form) {
    var panel = paymentPanel(form);
    if (!panel) return;
    panel.hidden = true;
    panel.classList.remove('is-active');
  }

  function showPaymentPanel(form, title, note) {
    var panel = paymentPanel(form);
    if (!panel) return null;
    var titleNode = panel.querySelector('[data-dp-payment-title]');
    var noteNode = panel.querySelector('[data-dp-payment-note]');
    if (titleNode) titleNode.textContent = title || 'Complete Payment';
    if (noteNode) noteNode.textContent = note || '';
    panel.hidden = false;
    panel.classList.add('is-active');
    return panel;
  }

  function setSubmitDisabled(form, disabled) {
    Array.prototype.forEach.call(form.querySelectorAll('button[type="submit"], [data-dp-submit], [data-dp-next], [data-dp-prev]'), function (button) {
      button.disabled = !!disabled;
    });
  }

  function renderPayPalAction(form, payment, donationNumber) {
    var panel = showPaymentPanel(
      form,
      'Continue to PayPal',
      'Donation ' + donationNumber + ' is pending. Continue to PayPal to approve the payment.'
    );
    if (!panel) return;

    var content = panel.querySelector('[data-dp-payment-content]');
    if (!content) return;

    if (!payment.approval_url) {
      setMessage(form, payment.message || 'PayPal approval link is unavailable.', true);
      return;
    }

    content.innerHTML =
      '<a class="dp-payment-button dp-payment-button-paypal" href="' + payment.approval_url + '">' +
      'Continue to PayPal' +
      '</a>';

    setMessage(form, 'Donation created: ' + donationNumber + '. Continue to PayPal to finish payment.', false);
  }

  function renderStripeAction(form, payment, donationNumber) {
    var panel = showPaymentPanel(
      form,
      'Complete Card Payment',
      'Donation ' + donationNumber + ' is pending. Enter card details below to complete the Stripe payment.'
    );
    if (!panel) return;

    var content = panel.querySelector('[data-dp-payment-content]');
    if (!content) return;

    if (!payment.publishable_key || !payment.client_secret) {
      setMessage(form, payment.message || 'Stripe payment details are unavailable.', true);
      return;
    }

    content.innerHTML =
      '<div class="dp-stripe-checkout">' +
      '<div class="dp-stripe-element" data-dp-stripe-element></div>' +
      '<button type="button" class="dp-payment-button" data-dp-stripe-confirm>Pay by Card</button>' +
      '<p class="dp-payment-inline-message" data-dp-stripe-message></p>' +
      '</div>';

    loadStripeJs()
      .then(function (StripeCtor) {
        var stripe = StripeCtor(payment.publishable_key);
        var elements = stripe.elements({ clientSecret: payment.client_secret });
        var paymentElement = elements.create('payment');
        var mount = content.querySelector('[data-dp-stripe-element]');
        var confirm = content.querySelector('[data-dp-stripe-confirm]');
        var inlineMessage = content.querySelector('[data-dp-stripe-message]');

        paymentElement.mount(mount);

        confirm.addEventListener('click', function () {
          confirm.disabled = true;
          if (inlineMessage) {
            inlineMessage.textContent = 'Confirming payment...';
          }

          stripe.confirmPayment({
            elements: elements,
            redirect: 'if_required',
            confirmParams: {
              return_url: window.location.href
            }
          }).then(function (result) {
            confirm.disabled = false;

            if (result.error) {
              if (inlineMessage) {
                inlineMessage.textContent = result.error.message || 'Stripe payment failed.';
              }
              setMessage(form, result.error.message || 'Stripe payment failed.', true);
              return;
            }

            if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
              if (inlineMessage) {
                inlineMessage.textContent = 'Payment completed successfully.';
              }
              setMessage(form, 'Payment completed for ' + donationNumber + '.', false);
              return;
            }

            if (inlineMessage) {
              inlineMessage.textContent = 'Stripe is processing the payment.';
            }
            setMessage(form, 'Stripe is processing the payment for ' + donationNumber + '.', false);
          }).catch(function () {
            confirm.disabled = false;
            if (inlineMessage) {
              inlineMessage.textContent = 'Stripe payment failed.';
            }
            setMessage(form, 'Stripe payment failed.', true);
          });
        });
      })
      .catch(function (error) {
        setMessage(form, error.message || 'Stripe checkout could not be loaded.', true);
      });

    setMessage(form, 'Donation created: ' + donationNumber + '. Enter card details to finish payment.', false);
  }

  function handlePayment(form, result) {
    var payment = (result.data && result.data.payment) || {};
    var donationNumber = result.data && result.data.donation_number;

    if (!payment || payment.success === false) {
      hidePaymentPanel(form);
      setMessage(
        form,
        'Donation created: ' + donationNumber + (payment && payment.message ? ' | ' + payment.message : ''),
        !payment || !payment.message
      );
      return;
    }

    if (payment.provider === 'paypal' && payment.approval_url) {
      renderPayPalAction(form, payment, donationNumber);
      return;
    }

    if (payment.provider === 'stripe' && payment.client_secret) {
      renderStripeAction(form, payment, donationNumber);
      return;
    }

    hidePaymentPanel(form);
    setMessage(form, 'Donation created: ' + donationNumber, false);
  }

  document.addEventListener('submit', function (event) {
    var form = event.target;
    if (!form.matches('[data-dp-form]')) return;

    event.preventDefault();
    hidePaymentPanel(form);
    setMessage(form, 'Processing...', false);
    setSubmitDisabled(form, true);

    var restUrl =
      form.getAttribute('data-rest-url') ||
      (window.donatepressFormConfig && window.donatepressFormConfig.restUrl);

    if (!restUrl) {
      setMessage(form, 'Form endpoint is not configured.', true);
      return;
    }

    var resolveCaptchaToken = function () {
      var provider = window.donatepressCaptchaProvider;
      if (typeof provider === 'function') {
        try {
          return Promise.resolve(provider(form));
        } catch (e) {
          return Promise.resolve('');
        }
      }
      if (typeof window.donatepressCaptchaToken === 'string') {
        return Promise.resolve(window.donatepressCaptchaToken);
      }
      return Promise.resolve('');
    };

    resolveCaptchaToken()
      .then(function (token) {
        var tokenField = form.querySelector('[data-dp-captcha-token]');
        if (tokenField && token) {
          tokenField.value = String(token);
        }
        var payload = serialize(form);
        var fee = form.querySelector('[data-dp-fee-recovery]');
        var feePercent = parseFloat(form.getAttribute('data-dp-fee-percent') || '0');
        if (fee && fee.checked && feePercent > 0 && payload.amount) {
          var baseAmount = parseFloat(payload.amount);
          if (!isNaN(baseAmount) && baseAmount > 0) {
            payload.base_amount = Number(baseAmount.toFixed(2));
            payload.amount = Number((baseAmount * (1 + feePercent / 100)).toFixed(2));
            payload.fee_recovery_applied = true;
          }
        }
        return fetch(restUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        });
      })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok) {
          var message = (result.data && result.data.message) || 'Submission failed.';
          setMessage(form, message, true);
          setSubmitDisabled(form, false);
          return;
        }
        handlePayment(form, result);
        setSubmitDisabled(form, false);
      })
      .catch(function () {
        setMessage(form, 'Network error. Please try again.', true);
        setSubmitDisabled(form, false);
      });
  });

  document.addEventListener('DOMContentLoaded', function () {
    var forms = document.querySelectorAll('[data-dp-form]');
    Array.prototype.forEach.call(forms, function (form) {
      setupMultiStep(form);
      setupAmountPresets(form);
    });
  });
})();
