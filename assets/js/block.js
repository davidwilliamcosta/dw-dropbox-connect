(function (wp, data) {
    'use strict';

    if (!wp || !wp.blocks) {
        return;
    }

    var el = wp.element.createElement;
    var __ = wp.i18n.__;
    var components = wp.components;
    var blockEditor = wp.blockEditor;
    var ServerSideRender = wp.serverSideRender;

    wp.blocks.registerBlockType('dw-dropbox-connect/gallery', {
        apiVersion: 3,
        title: __('Galeria do Dropbox', 'dw-dropbox-connect'),
        description: __('Mostra as imagens de uma pasta do Dropbox e acompanha as mudanças feitas nela.', 'dw-dropbox-connect'),
        category: 'media',
        icon: 'format-gallery',
        attributes: {
            feed: { type: 'string', default: '' },
            columns: { type: 'integer', default: 0 }
        },
        supports: { html: false, align: ['wide', 'full'] },
        edit: function (props) {
            var feeds = (data && data.feeds) || [];
            var attributes = props.attributes;
            var blockProps = blockEditor.useBlockProps();
            var options = [{ value: '', label: __('Escolha uma galeria', 'dw-dropbox-connect') }].concat(feeds);

            var picker = el(components.SelectControl, {
                label: __('Galeria', 'dw-dropbox-connect'),
                value: attributes.feed,
                options: options,
                onChange: function (value) {
                    props.setAttributes({ feed: value });
                }
            });

            var inspector = el(
                blockEditor.InspectorControls,
                null,
                el(
                    components.PanelBody,
                    { title: __('Galeria do Dropbox', 'dw-dropbox-connect'), initialOpen: true },
                    picker,
                    el(components.RangeControl, {
                        label: __('Colunas', 'dw-dropbox-connect'),
                        help: __('Com 0, vale o número definido na galeria.', 'dw-dropbox-connect'),
                        value: attributes.columns,
                        min: 0,
                        max: 8,
                        onChange: function (value) {
                            props.setAttributes({ columns: value || 0 });
                        }
                    })
                )
            );

            var body;
            if (attributes.feed) {
                body = el(ServerSideRender, { block: 'dw-dropbox-connect/gallery', attributes: attributes });
            } else if (feeds.length) {
                body = el(components.Placeholder, { icon: 'format-gallery', label: __('Galeria do Dropbox', 'dw-dropbox-connect') }, picker);
            } else {
                body = el(
                    components.Placeholder,
                    {
                        icon: 'format-gallery',
                        label: __('Galeria do Dropbox', 'dw-dropbox-connect'),
                        instructions: __('Nenhuma galeria criada ainda.', 'dw-dropbox-connect')
                    },
                    data && data.canManage
                        ? el('a', { className: 'components-button is-primary', href: data.settingsUrl, target: '_blank', rel: 'noopener' }, __('Criar galeria', 'dw-dropbox-connect'))
                        : null
                );
            }

            return el('div', blockProps, inspector, body);
        },
        save: function () {
            return null;
        }
    });
})(window.wp, window.dwdcBlock);
