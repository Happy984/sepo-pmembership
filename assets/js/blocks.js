/* Sepo Membership — blocks for plans, my membership, members area, sponsors and donate. No build step. */
(function (wp, cfg) {
  if (!wp || !wp.blocks) { return; }
  var el = wp.element.createElement;
  var __ = wp.i18n.__;
  var be = wp.blockEditor || wp.editor;
  var C = wp.components;
  var SSR = wp.serverSideRender;
  cfg = cfg || {};
  var tiers = cfg.tiers || [];
  var projects = cfg.projects || [];
  var collections = cfg.collections || [];

  function preview(name, attrs, props) {
    return el('div', props, SSR ? el(SSR, { block: name, attributes: attrs }) : null);
  }
  function set(props, key) { return function (v) { var o = {}; o[key] = v; props.setAttributes(o); }; }
  function text(props, key, label, help) { return el(C.TextControl, { label: label, help: help, value: props.attributes[key], onChange: set(props, key) }); }
  function tier(props, key, label, help) { return el(C.SelectControl, { label: label, help: help, value: props.attributes[key], options: tiers, onChange: set(props, key) }); }
  function block(name, o) {
    wp.blocks.registerBlockType(name, {
      apiVersion: 2,
      title: o.title,
      description: o.description,
      icon: o.icon,
      category: 'widgets',
      attributes: o.attributes || {},
      edit: function (props) {
        var bp = be.useBlockProps ? be.useBlockProps() : {};
        return el('div', bp,
          o.controls ? el(be.InspectorControls, null, el(C.PanelBody, { title: o.title, initialOpen: true }, o.controls(props))) : null,
          preview(name, props.attributes, { style: { pointerEvents: 'none' } }));
      },
      save: function () { return null; }
    });
  }
  var S = { type: 'string', default: '' };

  block('sepo-membership/plans', {
    title: __('Membership plans', 'sepo-membership'),
    description: __('The pricing table with a billing-period switch and the join dialog.', 'sepo-membership'),
    icon: 'id-alt',
    attributes: { tiers: S, period: S, title: S, intro: S },
    controls: function (p) {
      return [
        text(p, 'title', __('Heading', 'sepo-membership')),
        text(p, 'intro', __('Introduction', 'sepo-membership')),
        text(p, 'tiers', __('Only these plans', 'sepo-membership'), __('Plan ids separated by commas. Leave empty for all.', 'sepo-membership')),
        el(C.SelectControl, { label: __('Period shown first', 'sepo-membership'), value: p.attributes.period, onChange: set(p, 'period'), options: [
          { value: '', label: __('Monthly when offered', 'sepo-membership') },
          { value: 'monthly', label: __('Monthly', 'sepo-membership') },
          { value: 'quarterly', label: __('Quarterly', 'sepo-membership') },
          { value: 'annual', label: __('Yearly', 'sepo-membership') },
          { value: 'one_time', label: __('One-time', 'sepo-membership') }
        ] })
      ];
    }
  });

  block('sepo-membership/account', {
    title: __('My membership', 'sepo-membership'),
    description: __('The member\'s plan, payments waiting, history and choices.', 'sepo-membership'),
    icon: 'admin-users'
  });

  block('sepo-membership/members-area', {
    title: __('Members area', 'sepo-membership'),
    description: __('Downloads, links, discussions and suggestions for members, by plan.', 'sepo-membership'),
    icon: 'lock',
    attributes: { tier: S, discussionsTier: S, suggestionsTier: S, collection: S, show: S, title: S },
    controls: function (p) {
      return [
        text(p, 'title', __('Welcome heading', 'sepo-membership')),
        tier(p, 'tier', __('Who can enter', 'sepo-membership'), __('Members with an active plan at this level or above.', 'sepo-membership')),
        tier(p, 'discussionsTier', __('Discussions', 'sepo-membership')),
        tier(p, 'suggestionsTier', __('Suggestions and voting', 'sepo-membership')),
        el(C.SelectControl, { label: __('Resources from', 'sepo-membership'), value: p.attributes.collection, options: collections, onChange: set(p, 'collection') }),
        text(p, 'show', __('Sections', 'sepo-membership'), __('Any of downloads, links, discussions, suggestions, separated by commas. Leave empty for all.', 'sepo-membership'))
      ];
    }
  });

  block('sepo-membership/sponsors', {
    title: __('Sponsors', 'sepo-membership'),
    description: __('Projects to sponsor, sponsors by level, and members who chose to be listed.', 'sepo-membership'),
    icon: 'awards',
    attributes: { show: S, project: { type: 'number', default: 0 }, title: S, intro: S },
    controls: function (p) {
      return [
        text(p, 'title', __('Heading', 'sepo-membership')),
        text(p, 'intro', __('Introduction', 'sepo-membership')),
        el(C.SelectControl, { label: __('Sponsors of', 'sepo-membership'), value: p.attributes.project, options: projects.map(function (o) { return o.value === 0 ? { value: 0, label: __('Everything', 'sepo-membership') } : o; }), onChange: function (v) { p.setAttributes({ project: parseInt(v, 10) || 0 }); } }),
        text(p, 'show', __('Sections', 'sepo-membership'), __('Any of stats, projects, sponsors, members, separated by commas. Leave empty for all.', 'sepo-membership'))
      ];
    }
  });

  block('sepo-membership/donate', {
    title: __('Donate', 'sepo-membership'),
    description: __('A giving form with amount choices, for general donations or one project.', 'sepo-membership'),
    icon: 'heart',
    attributes: { project: { type: 'number', default: 0 }, amounts: S, title: S, intro: S, progress: { type: 'boolean', default: true } },
    controls: function (p) {
      return [
        text(p, 'title', __('Heading', 'sepo-membership')),
        text(p, 'intro', __('Introduction', 'sepo-membership')),
        el(C.SelectControl, { label: __('Gifts go to', 'sepo-membership'), value: p.attributes.project, options: projects, onChange: function (v) { p.setAttributes({ project: parseInt(v, 10) || 0 }); } }),
        text(p, 'amounts', __('Amounts', 'sepo-membership'), __('Separated by commas. Leave empty to use the setting.', 'sepo-membership')),
        el(C.ToggleControl, { label: __('Show progress towards the goal', 'sepo-membership'), checked: !!p.attributes.progress, onChange: set(p, 'progress') })
      ];
    }
  });
})(window.wp, window.SepoMembershipBlocks);
