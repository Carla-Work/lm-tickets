{{-- Casillas de productos (alta y detalle de empresa). Variables: $products, $checked (ids marcados). --}}
<fieldset class="checks">
    @foreach ($products as $product)
        <label>
            <input type="checkbox" name="productos[]" value="{{ $product->id }}" @checked(in_array($product->id, $checked, true))>
            <span>{{ $product->name }}@unless ($product->active) (desactivado)@endunless<small>{{ $product->description }}</small></span>
        </label>
    @endforeach
</fieldset>
