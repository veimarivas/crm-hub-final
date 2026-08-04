<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Lo que cobra Meta por mensaje (Bolivia)
    |--------------------------------------------------------------------------
    |
    | Desde el 1 de julio de 2025 Meta cobra POR MENSAJE (antes era por
    | conversación de 24 h) y la tarifa depende de la categoría de la plantilla
    | y del código de país de QUIEN RECIBE. Bolivia entra en el grupo "Resto de
    | Latinoamérica" de la tarjeta de tarifas.
    |
    | Lo que hay que tener clarísimo antes de mirar los números:
    |
    |  - Dentro de la ventana de servicio (24 h desde el último mensaje del
    |    cliente, o 72 h si vino de un anuncio Click-to-WhatsApp) el texto
    |    libre es GRATIS. Ahí no hay nada que estimar.
    |  - Fuera de la ventana el texto libre no es que cueste más: Meta
    |    directamente NO LO ENTREGA. Para llegar hace falta una plantilla
    |    aprobada, y ESA es la que se factura con las tarifas de abajo.
    |
    | Meta actualiza la tarjeta cada trimestre, así que esto es una ESTIMACIÓN
    | para que nadie mande 400 mensajes sin saber qué va a pagar — la factura
    | real manda. Si cambia, se toca acá y toda la app queda al día.
    |
    | Fuente: tarjeta de tarifas de WhatsApp para Bolivia publicada por Plivo,
    | consultada el 2026-08-03. La referencia oficial de Meta está en
    | developers.facebook.com/documentation/business-messaging/whatsapp/pricing
    |
    */

    'pricing' => [

        'country' => 'Bolivia',

        'currency' => 'USD',

        // Por mensaje entregado, en dólares.
        'rates' => [
            'marketing' => 0.0814,      // promociones, difusión — lo típico de un broadcast
            'utility' => 0.0124,        // confirmaciones, recordatorios de algo que el cliente pidió
            'authentication' => 0.0124, // códigos de verificación
            'service' => 0.0,           // respuestas dentro de la ventana: gratis
        ],

        // Solo para mostrar el equivalente en la moneda en que la gente piensa.
        // Tipo de cambio oficial del BCB (Bs por US$ 1).
        'bob_per_usd' => 6.96,

        'source' => 'Tarjeta de tarifas de WhatsApp para Bolivia (Plivo), consultada el 2026-08-03',

        'updated_at' => '2026-08-03',

    ],

];
