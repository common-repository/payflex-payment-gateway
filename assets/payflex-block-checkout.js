const { registerPaymentMethod } = wc.wcBlocksRegistry;

const PayflexPaymentMethod = {
    name: 'payflex',
    label: 'Payflex',
    content: <div>Pay with Payflex</div>,
    edit: <div>Pay with Payflex</div>,
    canMakePayment: () => true,
    ariaLabel: 'Payflex Payment Gateway',
    supports: {
        showSavedCards: true,
        showSaveOption: true,
        tokenizePayment: true,
    },
};

registerPaymentMethod(PayflexPaymentMethod);