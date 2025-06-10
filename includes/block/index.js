const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
const { getSetting } = window.wc.wcSettings;
const { createElement, useState, useEffect } = window.wp.element;
const { __ } = window.wp.i18n;


// Získání nastavení z dat poskytnutých PHP
const settings = getSetting('gopay_data', {});

// Funkce pro kontrolu dostupnosti Apple Pay
// Funkce pro kontrolu dostupnosti Apple Pay
const checkApplePayAvailability = () => {
	let applePayAvailable = false;
	if (window.ApplePaySession && window.ApplePaySession.canMakePayments()) {
		applePayAvailable = true;
	}
	return applePayAvailable;
};
// Funkce pro filtrování platebních metod
const filterPaymentMethods = (methods) => {
	const applePayAvailable = checkApplePayAvailability();

	// Filtruj metody - odstraň Apple Pay, pokud není dostupné
	return methods.filter(method => {
		if (method.id === 'APPLE_PAY' && !applePayAvailable) {
			return false;
		}
		return true;
	});

};
// Odstranění Apple Pay z platebních metod, pokud není dostupné
const filteredMethods = filterPaymentMethods(settings.paymentMethods);


// Komponenta pro výběr platební metody GoPay
const GoPayMethodSelection = (props) => {
	const [selectedMethod, setSelectedMethod] = useState('');
	const { eventRegistration, emitResponse } = props;
	const { onPaymentSetup } = eventRegistration;

	useEffect(() => {
		// Automaticky vybrat první metodu
		if (filteredMethods && filteredMethods.length > 0 && !selectedMethod) {
			setSelectedMethod(filteredMethods[0].id);
		}
	}, []);

	useEffect(() => {
		const unsubscribe = onPaymentSetup(() => {
			if (!selectedMethod) {
				return {
					type: 'error',
					message: __('Vyberte prosím platební metodu', 'gopay-gateway'),
				};
			}

			return {
				type: 'success',
				meta: {
					paymentMethodData: {
						gopay_payment_method: selectedMethod,
					},
				},
			};
		});

		return () => unsubscribe();
	}, [onPaymentSetup, selectedMethod]);

	// Pokud nejsou žádné platební metody, nic nezobrazovat
	if (!filteredMethods || !filteredMethods.length) {
		return null;
	}

	return createElement('div', { className: 'wc-gopay-payment-methods' },
		settings.description && createElement('p', { className: 'wc-gopay-description' }, settings.description),
		createElement('div', { className: 'wc-gopay-methods-list' },
			filteredMethods.map((method) =>
				createElement('div', {
						key: method.id,
						className: `wc-gopay-method ${selectedMethod === method.id ? 'selected' : ''}`,
						onClick: () => setSelectedMethod(method.id)
					},
					createElement('div', { className: 'wc-gopay-method-input' },
						createElement('input', {
							type: 'radio',
							name: 'gopay-payment-method',
							value: method.id,
							id: method.id,
							checked: selectedMethod === method.id,
							onChange: () => setSelectedMethod(method.id)
						}),
						createElement('span', {}, method.label),
						method.image && createElement('img', {
							src: method.image,
							alt: method.label,
							className: 'wc-gopay-method-image',
						})
					)
				)
			)
		)
	);
};

// Definice platební brány GoPay
const GoPayGateway = {
	name: 'wc_gopay_gateway',
	label: settings.title || __('GoPay', 'gopay-gateway'),
	content: createElement(GoPayMethodSelection),
	edit: createElement(GoPayMethodSelection),
	canMakePayment: () => true,
	ariaLabel: settings.title || __('GoPay', 'gopay-gateway'),
	supports: settings.supports || {
		features: ['products']
	}
};

// Registrace platební brány
registerPaymentMethod(GoPayGateway);