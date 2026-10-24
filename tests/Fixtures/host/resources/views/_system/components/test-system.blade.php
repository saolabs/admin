@const([$message, $setMessage] = useState('Hello from _system'))

<div class="system-component">
    <h1>{{ $message }}</h1>
    <button @click($setMessage('Updated!'))>Click me</button>
</div>
