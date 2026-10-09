<?php

declare(strict_types=1);

// Only the rules our forms use today; anything else falls back to Laravel's English.
return [
    'confirmed' => 'تأكيد حقل :attribute غير مطابق.',
    'current_password' => 'كلمة المرور غير صحيحة.',
    'email' => 'يجب أن يكون حقل :attribute عنوان بريد إلكتروني صالحًا.',
    'in' => 'القيمة المختارة في حقل :attribute غير صالحة.',
    'lowercase' => 'يجب أن يكون حقل :attribute بأحرف صغيرة.',
    'max' => [
        'string' => 'يجب ألا يتجاوز حقل :attribute :max حرفًا.',
    ],
    'min' => [
        'string' => 'يجب أن يحتوي حقل :attribute على :min أحرف على الأقل.',
    ],
    'password' => [
        'letters' => 'يجب أن يحتوي حقل :attribute على حرف واحد على الأقل.',
        'mixed' => 'يجب أن يحتوي حقل :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers' => 'يجب أن يحتوي حقل :attribute على رقم واحد على الأقل.',
        'symbols' => 'يجب أن يحتوي حقل :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'ظهرت قيمة حقل :attribute في تسريب للبيانات. يُرجى اختيار قيمة أخرى.',
    ],
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون حقل :attribute نصًا.',
    'unique' => 'قيمة حقل :attribute مستخدمة من قبل.',

    'attributes' => [
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'current_password' => 'كلمة المرور الحالية',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'locale' => 'اللغة',
        'business_name' => 'اسم المحل',
        'code' => 'الرمز',
        'recovery_code' => 'رمز الاسترداد',
    ],
];
