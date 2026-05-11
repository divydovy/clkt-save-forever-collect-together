(function (wp) {
    'use strict';

    var __ = wp.i18n.__;
    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var registerBlockType = wp.blocks.registerBlockType;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var useBlockProps = wp.blockEditor.useBlockProps;
    var PanelBody = wp.components.PanelBody;
    var ToggleControl = wp.components.ToggleControl;
    var TextControl = wp.components.TextControl;
    var cloudIcon = wp.icons && wp.icons.cloud ? wp.icons.cloud : 'cloud';

    registerBlockType('clkt/permaweb-links', {
        icon: cloudIcon,

        edit: function (props) {
            var attributes = props.attributes;
            var setAttributes = props.setAttributes;
            var showTitle = attributes.showTitle;
            var customTitle = attributes.customTitle;
            var showHistory = attributes.showHistory;
            var blockProps = useBlockProps();

            return el(
                Fragment,
                null,
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        { title: __('Settings', 'clkt-save-forever-collect-together') },
                        el(ToggleControl, {
                            label: __('Show Title', 'clkt-save-forever-collect-together'),
                            checked: showTitle,
                            onChange: function (value) {
                                setAttributes({ showTitle: value });
                            }
                        }),
                        showTitle && el(TextControl, {
                            label: __('Custom Title', 'clkt-save-forever-collect-together'),
                            value: customTitle,
                            onChange: function (value) {
                                setAttributes({ customTitle: value });
                            },
                            placeholder: __('CLKT - Permanent Record', 'clkt-save-forever-collect-together'),
                            help: __('Leave empty to use the default title from settings.', 'clkt-save-forever-collect-together')
                        }),
                        el(ToggleControl, {
                            label: __('Show Version History', 'clkt-save-forever-collect-together'),
                            checked: showHistory,
                            onChange: function (value) {
                                setAttributes({ showHistory: value });
                            },
                            help: __('When enabled, shows all uploaded versions. Otherwise, shows only the latest version.', 'clkt-save-forever-collect-together')
                        })
                    )
                ),
                el(
                    'div',
                    blockProps,
                    el(
                        'div',
                        { className: 'clkt-permaweb-links clkt-block-preview' },
                        el(
                            'div',
                            {
                                style: {
                                    padding: '1.5em',
                                    background: '#f8f9fa',
                                    borderLeft: '4px solid #D4A857',
                                    borderRadius: '4px'
                                }
                            },
                            showTitle && el(
                                'h3',
                                { style: { marginTop: 0 } },
                                customTitle || __('CLKT - Permanent Record', 'clkt-save-forever-collect-together')
                            ),
                            el(
                                'p',
                                { style: { color: '#666', fontStyle: 'italic', marginBottom: 0 } },
                                __('Arweave links will be displayed here on the published post.', 'clkt-save-forever-collect-together')
                            )
                        )
                    )
                )
            );
        },

        save: function () {
            return null;
        }
    });
})(window.wp);
