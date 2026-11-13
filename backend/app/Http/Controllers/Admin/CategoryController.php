<?php
namespace App\Http\Controllers\Admin;
use App\Http\Controllers\Controller; use App\Models\Category; use Illuminate\Http\Request; use Illuminate\Validation\Rule; use Illuminate\Support\Str;
class CategoryController extends Controller {
public function index(){return view('admin.categories.index',['categories'=>Category::withCount('tickets')->orderBy('name')->paginate(20)]);}
public function create(){return view('admin.categories.form',['category'=>new Category]);}
public function store(Request $r){$d=$r->validate(['name'=>'required|string|max:100|unique:categories,name','slug'=>'nullable|string|max:120|unique:categories,slug','description'=>'nullable|string|max:2000','is_active'=>'nullable|boolean']);$d['slug']=$d['slug']?:Str::slug($d['name']);$d['is_active']=$r->boolean('is_active');Category::create($d);return redirect()->route('admin.categories.index')->with('status','Categoria criada.');}
public function edit(Category $category){return view('admin.categories.form',compact('category'));}
public function update(Request $r,Category $category){$d=$r->validate(['name'=>'required|string|max:100', 'slug'=>['nullable','string','max:120',Rule::unique('categories')->ignore($category->id)],'description'=>'nullable|string|max:2000','is_active'=>'nullable|boolean']);$d['slug']=$d['slug']?:Str::slug($d['name']);$d['is_active']=$r->boolean('is_active');$category->update($d);return redirect()->route('admin.categories.index')->with('status','Categoria atualizada.');}
public function destroy(Category $category){abort_if($category->tickets()->exists(),409,'Categoria vinculada a solicitações; desative-a em vez de excluir.');$category->delete();return back()->with('status','Categoria removida.');}}
