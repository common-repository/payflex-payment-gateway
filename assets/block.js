const { registerBlockType } = wp.blocks;
const { createElement } = wp.element;
// Get plugin url (assets/widget-icon.png)
payflexImageUrl =  payflexBlockVars.pluginUrl + 'assets/widget-icon.png';

registerBlockType('payflex/widget', {
    title: 'Payflex Widget',
    icon: 'money',
    category: 'widgets',
    edit: () => createElement('img' , {src: payflexImageUrl, alt: 'Payflex Logo'}),
    save: () => null,
});