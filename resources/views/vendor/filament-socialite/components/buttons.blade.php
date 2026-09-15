<div>
    @php
        $providerStyles = [
            'google' => [
                'iconColor' => '#EA4335',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-[#EA4335]/60 dark:hover:border-[#EA4335]/70',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
            'linkedin-openid' => [
                'iconColor' => '#0A66C2',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-[#0A66C2]/60 dark:hover:border-[#0A66C2]/70',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
            'twitter-oauth-2' => [
                'iconColor' => 'currentColor',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-gray-500 dark:hover:border-gray-500',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
            'instagram' => [
                'iconColor' => '#E4405F',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-[#E4405F]/60 dark:hover:border-[#E4405F]/70',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
            'facebook' => [
                'iconColor' => '#1877F2',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-[#1877F2]/60 dark:hover:border-[#1877F2]/70',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
            'microsoft' => [
                'iconColor' => '#00A4EF',
                'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-[#00A4EF]/60 dark:hover:border-[#00A4EF]/70',
                'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
            ],
        ];
    @endphp

    <div class="flex flex-col gap-y-5 mt-6">
        @if ($messageBag->isNotEmpty())
            @foreach($messageBag->all() as $value)
                <p class="fi-fo-field-wrp-error-message text-sm text-danger-600 dark:text-danger-400">{{ __($value) }}</p>
            @endforeach
        @endif

        @if (count($visibleProviders))
            @if($showDivider)
                <div class="flex items-center my-4 text-center">
                    <div class="flex-grow border-t border-gray-200 dark:border-gray-800"></div>
                    <span class="px-3.5 text-xs font-medium uppercase tracking-wider text-gray-500 dark:text-gray-400 shrink-0">
                        {{ __('filament-socialite::auth.login-via') }}
                    </span>
                    <div class="flex-grow border-t border-gray-200 dark:border-gray-800"></div>
                </div>
            @endif

            <div class="grid @if(count($visibleProviders) > 1) grid-cols-2 @else grid-cols-1 @endif gap-3 mt-3">
                @foreach($visibleProviders as $key => $provider)
                    @php
                        $style = $providerStyles[$key] ?? [
                            'iconColor' => 'inherit',
                            'bgClass' => 'bg-white hover:bg-gray-50 dark:bg-gray-900 dark:hover:bg-gray-800',
                            'borderClass' => 'border-gray-300 dark:border-gray-700 hover:border-primary-500',
                            'textClass' => 'text-gray-700 dark:text-gray-200 hover:text-gray-900 dark:hover:text-white',
                        ];
                    @endphp
                    <a
                        href="{{ route($socialiteRoute, $key) }}"
                        class="flex items-center justify-center gap-2 px-3 h-[36px] min-h-[36px] max-h-[36px] text-sm font-medium transition-all duration-200 border rounded-lg shadow-sm {{ $style['bgClass'] }} {{ $style['borderClass'] }} {{ $style['textClass'] }}"
                        style="text-decoration: none; height: 36px; min-height: 36px; max-height: 36px;"
                    >
                        @if ($key === 'google')
                            <svg viewBox="0 0 24 24" width="16" height="16" class="w-4 h-4 shrink-0" style="width: 16px; height: 16px; min-width: 16px; max-width: 16px; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                                <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                                <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                                <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                            </svg>
                        @elseif ($key === 'microsoft')
                            <svg viewBox="0 0 24 24" width="16" height="16" class="w-4 h-4 shrink-0" style="width: 16px; height: 16px; min-width: 16px; max-width: 16px; flex-shrink: 0;" xmlns="http://www.w3.org/2000/svg">
                                <path fill="#f35325" d="M1 1h10v10H1z"/>
                                <path fill="#81bc06" d="M13 1h10v10H13z"/>
                                <path fill="#05a6f0" d="M1 13h10v10H1z"/>
                                <path fill="#ffba08" d="M13 13h10v10H13z"/>
                            </svg>
                        @elseif ($provider->getIcon())
                            <x-filament::icon
                                :icon="$provider->getIcon()"
                                class="w-4 h-4 shrink-0"
                                style="color: {{ $style['iconColor'] }}; width: 16px; height: 16px; min-width: 16px; max-width: 16px; flex-shrink: 0;"
                            />
                        @endif
                        <span>{{ $provider->getLabel() }}</span>
                    </a>
                @endforeach
            </div>
        @else
            <span></span>
        @endif
    </div>
</div>

