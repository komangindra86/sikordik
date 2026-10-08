@props(['kind' => 'placement', 'value' => null, 'tone' => null, 'label' => null])
<span {{ $attributes->class(['badge', 'badge-'.($tone ?? \App\Support\Ui::tone($kind, $value))]) }}>{{ $label ?? \App\Support\Ui::label($kind, $value) }}</span>
