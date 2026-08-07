<?php

namespace App\Services\DigitalPipeline;

use App\Models\PipelineStage;

/**
 * Automatizaciones de etapa ya armadas.
 *
 * Se ofrecen filtradas por el tipo de etapa: lo que tiene sentido al
 * ganar un lead no es lo mismo que al perderlo, y mostrarlo todo junto
 * obliga a leer seis opciones para descartar cuatro.
 */
class Recipes
{
    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        return [
            [
                'slug' => 'bienvenida',
                'title' => 'Saludar apenas entra',
                'summary' => 'Manda un WhatsApp de bienvenida en cuanto el lead llega a esta etapa.',
                'why' => 'Responder en el primer minuto es lo que más sube la conversión.',
                'stage_types' => [PipelineStage::TYPE_OPEN],
                'needs_whatsapp' => true,
                'actions' => [
                    ['action_type' => 'send_whatsapp', 'config' => [
                        'text' => '¡Hola {name}! 👋 Gracias por tu interés. Soy de ESAM y te acompaño con la información que necesites.',
                    ]],
                ],
            ],
            [
                'slug' => 'llamar-pronto',
                'title' => 'Recordar llamar en 2 horas',
                'summary' => 'Crea una tarea de llamada para el responsable del lead con vencimiento en 2 horas.',
                'why' => 'Un lead nuevo sin llamada el mismo día se enfría; la tarea lo hace visible.',
                'stage_types' => [PipelineStage::TYPE_OPEN],
                'actions' => [
                    ['action_type' => 'create_task', 'config' => [
                        'text' => 'Llamar a {name} — interesado en {title}',
                        'task_type' => 'call',
                        'due_in_hours' => 2,
                    ]],
                ],
            ],
            [
                'slug' => 'seguimiento-48h',
                'title' => 'Seguimiento a las 48 horas',
                'summary' => 'Deja una tarea de seguimiento para dos días después.',
                'why' => 'Sirve para etapas de espera: propuesta enviada, esperando documentos, etc.',
                'stage_types' => [PipelineStage::TYPE_OPEN],
                'actions' => [
                    ['action_type' => 'create_task', 'config' => [
                        'text' => 'Dar seguimiento a {name} — sigue en {stage}',
                        'task_type' => 'follow_up',
                        'due_in_hours' => 48,
                    ]],
                ],
            ],
            [
                'slug' => 'aviso-y-tarea',
                'title' => 'Avisar por WhatsApp y agendar seguimiento',
                'summary' => 'Manda un mensaje al lead y además crea la tarea de seguimiento.',
                'why' => 'Las dos puntas cubiertas: el cliente sabe en qué va y el equipo tiene el recordatorio.',
                'stage_types' => [PipelineStage::TYPE_OPEN],
                'needs_whatsapp' => true,
                'actions' => [
                    ['action_type' => 'send_whatsapp', 'config' => [
                        'text' => '{name}, ya avanzamos con tu solicitud de {title}. En breve te contamos los siguientes pasos.',
                    ]],
                    ['action_type' => 'create_task', 'config' => [
                        'text' => 'Confirmar con {name} que recibió la información',
                        'task_type' => 'follow_up',
                        'due_in_hours' => 24,
                    ]],
                ],
            ],
            [
                'slug' => 'felicitar-ganado',
                'title' => 'Felicitar al cerrar',
                'summary' => 'Manda un WhatsApp de bienvenida al programa y deja la nota del cierre.',
                'why' => 'El cierre es el mejor momento para pedir referidos; que no pase en silencio.',
                'stage_types' => [PipelineStage::TYPE_WON],
                'needs_whatsapp' => true,
                'actions' => [
                    ['action_type' => 'send_whatsapp', 'config' => [
                        'text' => '¡Felicidades {name}! 🎉 Ya eres parte de {title}. En breve te enviamos los datos de acceso.',
                    ]],
                    ['action_type' => 'add_note', 'config' => [
                        'text' => 'Lead ganado por {value}. Pendiente: enviar credenciales y pedir referidos.',
                    ]],
                ],
            ],
            [
                'slug' => 'reactivar-perdido',
                'title' => 'Reactivar en 30 días',
                'summary' => 'Deja una tarea para volver a contactar dentro de un mes y una nota del motivo.',
                'why' => 'Un “no” suele ser un “ahora no”. Sin tarea, ese lead no vuelve a mirarse nunca.',
                'stage_types' => [PipelineStage::TYPE_LOST],
                'actions' => [
                    ['action_type' => 'add_note', 'config' => [
                        'text' => 'Lead perdido en {stage}. Anotar el motivo real para el próximo intento.',
                    ]],
                    ['action_type' => 'create_task', 'config' => [
                        'text' => 'Reactivar a {name} — ver si cambió su situación',
                        'task_type' => 'follow_up',
                        'due_in_hours' => 720,
                    ]],
                ],
            ],
        ];
    }

    public static function find(string $slug): ?array
    {
        foreach (self::all() as $recipe) {
            if ($recipe['slug'] === $slug) {
                return $recipe;
            }
        }

        return null;
    }

    /** Catálogo para la UI, agrupado por el tipo de etapa donde aplica. */
    public static function gallery(): array
    {
        return array_map(fn (array $r) => [
            'slug' => $r['slug'],
            'title' => $r['title'],
            'summary' => $r['summary'],
            'why' => $r['why'],
            'stage_types' => $r['stage_types'],
            'needs_whatsapp' => $r['needs_whatsapp'] ?? false,
            'actions' => array_map(fn ($a) => $a['action_type'], $r['actions']),
        ], self::all());
    }
}
