(function (blocks, element, blockEditor, components) {
  var el = element.createElement;
  var InspectorControls = blockEditor.InspectorControls;
  var PanelBody = components.PanelBody;
  var TextControl = components.TextControl;
  var ToggleControl = components.ToggleControl;

  blocks.registerBlockType('donatepress/form', {
    title: 'DonatePress Form',
    icon: 'heart',
    category: 'widgets',
    attributes: {
      id: { type: 'number', default: 1 },
      amount: { type: 'number', default: 25 },
      currency: { type: 'string', default: 'USD' },
      gateway: { type: 'string', default: 'stripe' },
      recurring: { type: 'boolean', default: false }
    },
    edit: function (props) {
      var a = props.attributes;
      return el(
        'div',
        { className: 'donatepress-form-block-editor' },
        el(
          InspectorControls,
          {},
          el(
            PanelBody,
            { title: 'Form Settings', initialOpen: true },
            el(TextControl, {
              label: 'Form ID',
              type: 'number',
              value: String(a.id || 1),
              onChange: function (value) { props.setAttributes({ id: parseInt(value || '1', 10) || 1 }); }
            }),
            el(TextControl, {
              label: 'Default Amount',
              type: 'number',
              value: String(a.amount || 25),
              onChange: function (value) { props.setAttributes({ amount: parseFloat(value || '25') || 25 }); }
            }),
            el(TextControl, {
              label: 'Currency',
              value: a.currency || 'USD',
              onChange: function (value) { props.setAttributes({ currency: (value || 'USD').toUpperCase() }); }
            }),
            el(TextControl, {
              label: 'Gateway',
              value: a.gateway || 'stripe',
              onChange: function (value) { props.setAttributes({ gateway: (value || 'stripe').toLowerCase() }); }
            }),
            el(ToggleControl, {
              label: 'Recurring enabled by default',
              checked: !!a.recurring,
              onChange: function (value) { props.setAttributes({ recurring: !!value }); }
            })
          )
        ),
        el('p', {}, 'DonatePress form will render on the front end.'),
        el('code', {}, '[donatepress_form id="' + (a.id || 1) + '" amount="' + (a.amount || 25) + '" currency="' + (a.currency || 'USD') + '" gateway="' + (a.gateway || 'stripe') + '" recurring="' + (!!a.recurring) + '"]')
      );
    },
    save: function () {
      return null;
    }
  });
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components);

