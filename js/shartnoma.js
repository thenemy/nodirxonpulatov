
document.querySelector('.form-section form').addEventListener('submit', function(e) {
  e.preventDefault(); // Запрещаем странице обновляться
  
  var form = this;
  var formData = new FormData(form);
  var submitButton = form.querySelector('.submit-btn');
  
  // Создаем блок для красивого сообщения, если его еще нет на странице
  var msgDiv = document.getElementById('form-status-msg');
  if (!msgDiv) {
    msgDiv = document.createElement('div');
    msgDiv.id = 'form-status-msg';
    msgDiv.style.marginTop = '15px';
    msgDiv.style.fontWeight = 'bold';
    msgDiv.style.textAlign = 'center';
    form.appendChild(msgDiv);
  }
  
  // Визуально показываем пользователю, что процесс пошел
  submitButton.disabled = true;
  submitButton.innerText = "Yuborilmoqda..."; 
  msgDiv.innerText = ""; 
  
  // Отправляем данные в Google Таблицу
  fetch(form.action, {
    method: 'POST',
    body: new URLSearchParams(formData)
  })
  .then(response => response.json())
  .then(data => {
    if(data.result === 'success') {
      // Сообщение при успешной отправке
      msgDiv.style.color = '#27ae60'; // Зеленый цвет
      msgDiv.innerText = "Muvaffaqiyatli yuborildi!";
      
      form.reset(); // Очищаем поля формы
    } else {
      msgDiv.style.color = '#c0392b'; // Красный цвет
      msgDiv.innerText = "Xatolik yuz berdi. Qayta urinib ko'ring.";
      console.error(data.message);
    }
  })
  .catch(error => {
    msgDiv.style.color = '#c0392b';
    msgDiv.innerText = "Tarmoq xatoligi. Internetni tekshiring.";
  })
  .finally(() => {
    // Возвращаем кнопку в рабочее состояние
    submitButton.disabled = false;
    submitButton.innerText = "Ariza jonatish";
  });
});
